<?php
/**
 * This addon fires off the purchase & addentee data to a Zapier endpoint upon each ticket purchase completion.
 */
class CampTix_Addon_Zapier extends CampTix_Addon {
    
    // Document all the various status levels that a ticket can be here:
    protected $statii = [
        Camptix_Plugin::PAYMENT_STATUS_CANCELLED => 'Cancelled',
    	Camptix_Plugin::PAYMENT_STATUS_COMPLETED => 'Completed',
    	Camptix_Plugin::PAYMENT_STATUS_PENDING => 'Pending',
    	Camptix_Plugin::PAYMENT_STATUS_FAILED => 'Failed',
    	Camptix_Plugin::PAYMENT_STATUS_TIMEOUT => 'Timeout',
    	Camptix_Plugin::PAYMENT_STATUS_REFUNDED => 'Refunded',
    	Camptix_Plugin::PAYMENT_STATUS_REFUND_FAILED => 'Refund Failed',
        ];

	/**
	 * Runs during camptix_init, @see CampTix_Addon
	 */
	function camptix_init() {
	    global $camptix;
	    // Register the action handler for us that will receive (and send) the data:
		add_action( 'camptix_payment_result', [ $this, 'camptix_zapier_action' ], 10, 3 );

		// Set up a new menu of config for us:
		add_filter( 'camptix_setup_sections', [ $this, 'camptix_zapier_menu_sections' ], 10, 1);
		add_action( 'camptix_menu_setup_controls', [ $this, 'camptix_zapier_menu_setup' ], 10, 1 );
		add_filter( 'camptix_validate_options', [ $this, 'camptix_zapier_menu_validate' ], 10, 2 );
	}
	
	/**
	 * The payment trigger that will receive the data and send it on to Zapier:
	 */
	function camptix_zapier_action($payment_token, $result, $data) {
	    global $camptix;

        // Figure out our endpoint that we are going to be hitting:
        $options = $camptix->get_options();
        $o = 'zapier_hook_'.$result;
        $endpoint = !empty($options[$o]) ? $options[$o] : false;

        if (!$endpoint) { return; } // Nothing to do for this endpoint

        // Start our result class:	    
	    $output = new StdClass;
	    $output->payment_token = $payment_token;
	    $output->result_type = $result;
	    $output->data = $data;

        // Some data to calculate:
	    $emails = [];
	    
	    // Add in some basic info:
	    $output->event = get_bloginfo( 'name' );
        $output->site = get_site_url();
        $output->order_id = hash('crc32', $output->event) . "-" . hash('crc32', $payment_token); // Make our own 'prettier' ID than using the access token or something
        $output->timestamp = date_format(new DateTime(), DateTime::RSS);

        // We need to read in all the information about the attendees.        
        $attendees = get_posts( array(
			'posts_per_page' => -1,
			'post_type' => 'tix_attendee',
			'post_status' => array( 'draft', 'pending', 'publish', 'cancel', 'refund', 'failed' ),
			'meta_query' => array(
				array(
					'key' => 'tix_payment_token',
					'compare' => '=',
					'value' => $payment_token,
					'type' => 'CHAR',
				),
			),
		) );

		if ( ! $attendees ) {
			//$this->log( 'Could not find attendees by payment token', null, $_POST );
			die();
		}

        // Loop through all Attendees/tickets now:
        $output->attendees = [];
        foreach ($attendees as $k => $att) {
            // Prep a storage class:
            $ticket = new StdClass;
            
            // Pick up data that we care about:
            foreach (['tix_access_token', 'tix_payment_token', 'tix_edit_token', 'tix_payment_method', 'tix_timestamp',
                      'tix_first_name', 'tix_last_name', 'tix_email', 'tix_ticket_price', 'tix_ticket_discounted_price',
                      'tix_order_total', 'tix_coupon', 'tix_transaction_id', 'tix_payment_method'] as $midx) {
                $ticket->{$midx} = get_post_meta( $att->ID, $midx, true );
            }

            // Ticket Detail:
            $tix_id = intval( get_post_meta( $att->ID, 'tix_ticket_id', true ) );
            $tix = get_post($tix_id);
            $ticket->ticket_name = $tix->post_title;
            $ticket->ticket_desc = $tix->post_excerpt;            

            // Read in all the custom questions and add them:
    		$questions = $camptix->get_sorted_questions( $tix_id );
    		$answers = get_post_meta( $att->ID, 'tix_questions', true );
    		foreach ( $questions as $question ) {
    			if ( isset( $answers[ $question->ID ] ) ) {
    				$answer = $answers[ $question->ID ];
    				if ( is_array( $answer ) ) {
    					$answer = implode( ', ', $answer );
    				}
				    $ticket->{"question_{$question->post_title}"} = $answer;
			    }
    		}

    		// Add the ticket to the output - encode it to protect it against Zapier's engine rebreaking it.
    		//  Devs will have to use a Javascript Action inside of Zapier to decode this if they want it.
    		$output->attendees[] = json_encode($ticket);
    		
    		$emails[$ticket->tix_email] = $ticket->tix_email;
    		
    		// If this is the first one, save a few items to the top level so we have some data to work with in Zapier:
    		if (!$k) {
    		    $output->coupon = $ticket->tix_coupon;
    		    $output->total = $ticket->tix_order_total;
    		    $output->formatted_total = money_format("%.2n", $ticket->tix_order_total); // Probably should make this locale dependant at some point
    		    $output->payment_method = $ticket->tix_payment_method;
    		    $output->receipt_name = $ticket->tix_first_name . ' ' . $ticket->tix_last_name;
    		    $output->receipt_first = $ticket->tix_first_name;
    		    $output->receipt_last = $ticket->tix_last_name;
    		}
		}
		
		// Now let's attempt to standardize a handful of things across payment processors, even though the data will exist in raw form:
		$output->payment = new StdClass;
        $output->payment->transaction_id = $output->data['transaction_id'] ?? '';
        $output->payment->address_1 = $output->data['transaction_details']['raw']['charge']['source']['address_line1'] ??
                                      $output->data['transaction_details']['raw']['token']['card']['address_line1'] ?? 
                                      $output->data['transaction_details']['checkout']['SHIPTOSTREET'] ?? 
                                      '';
        $output->payment->address_2 = $output->data['transaction_details']['raw']['charge']['source']['address_line2'] ??
                                      $output->data['transaction_details']['raw']['token']['card']['address_line2'] ?? 
                                      '';
        $output->payment->address_state = $output->data['transaction_details']['raw']['charge']['source']['address_state'] ??
                                          $output->data['transaction_details']['raw']['token']['card']['address_state'] ?? 
                                          $output->data['transaction_details']['checkout']['SHIPTOSTATE'] ?? 
                                          '';
        $output->payment->address_city = $output->data['transaction_details']['raw']['charge']['source']['address_city'] ??
                                         $output->data['transaction_details']['raw']['token']['card']['address_city'] ?? 
                                         $output->data['transaction_details']['checkout']['SHIPTOCITY'] ?? 
                                         '';
        $output->payment->address_country = $output->data['transaction_details']['raw']['charge']['source']['address_country'] ??
                                            $output->data['transaction_details']['raw']['charge']['source']['country'] ??
                                            $output->data['transaction_details']['raw']['token']['card']['address_country'] ??
                                            $output->data['transaction_details']['raw']['token']['card']['country'] ?? 
                                            $output->data['transaction_details']['checkout']['SHIPTOCOUNTRYCODE'] ?? 
                                            $output->data['transaction_details']['checkout']['COUNTRYCODE'] ?? 
                                            '';
        $output->payment->address_zip = $output->data['transaction_details']['raw']['charge']['source']['address_zip'] ??
                                        $output->data['transaction_details']['raw']['token']['card']['address_zip'] ?? 
                                        $output->data['transaction_details']['checkout']['SHIPTOZIP'] ?? 
                                        '';
        $output->payment->last4 = $output->data['transaction_details']['raw']['charge']['source']['last4'] ??
                                  $output->data['transaction_details']['raw']['token']['card']['last4'] ?? '';
        $output->payment->exp_month = $output->data['transaction_details']['raw']['charge']['source']['exp_month'] ??
                                      $output->data['transaction_details']['raw']['token']['card']['exp_month'] ?? '';
        $output->payment->exp_year = $output->data['transaction_details']['raw']['charge']['source']['exp_year'] ??
                                     $output->data['transaction_details']['raw']['token']['card']['exp_year'] ?? '';
        $output->payment->check_zip = $output->data['transaction_details']['raw']['charge']['source']['address_zip_check'] ??
                                      $output->data['transaction_details']['raw']['token']['card']['address_zip_check'] ?? '';
        $output->payment->check_cvc = $output->data['transaction_details']['raw']['charge']['source']['cvc_check'] ??
                                      $output->data['transaction_details']['raw']['token']['card']['cvc_check'] ?? '';
        $output->payment->check_zip = $output->data['transaction_details']['raw']['charge']['source']['address_zip_check'] ??
                                      $output->data['transaction_details']['raw']['token']['card']['address_zip_check'] ?? '';
        $output->payment->check_address = $output->data['transaction_details']['raw']['charge']['source']['address_line1_check'] ??
                                          $output->data['transaction_details']['raw']['token']['card']['address_line1_check'] ?? '';
        $output->payment->email = $output->data['transaction_details']['raw']['charge']['receipt_email'] ??
                                  $output->data['transaction_details']['raw']['token']['email'] ??
                                  $output->data['transaction_details']['checkout']['EMAIL'] ?? 
                                  reset($emails) ?? 
                                  '';
        if (!empty($output->payment->email)) {
            $emails[$output->payment->email] = $output->payment->email;
        }
        $output->payment->fingerprint = $output->data['transaction_details']['raw']['charge']['source']['fingerprint'] ??
                                        $output->data['transaction_details']['raw']['token']['card']['fingerprint'] ?? '';
        $output->payment->funding = $output->data['transaction_details']['raw']['charge']['source']['funding'] ??
                                    $output->data['transaction_details']['raw']['token']['card']['funding'] ?? 
                                    $output->data['transaction_details']['raw']['PAYMENTINFO_0_PAYMENTTYPE'] ?? 
                                    '';
        $output->payment->brand = $output->data['transaction_details']['raw']['charge']['source']['brand'] ??
                                  $output->data['transaction_details']['raw']['token']['card']['brand'] ?? '';
        $output->payment->risk = $output->data['transaction_details']['raw']['charge']['outcome']['risk_level'] ?? '';
        $output->payment->client_ip = $output->data['transaction_details']['raw']['token']['client_ip'] ?? '';
        $output->payment->currency = strtoupper(
                                     $output->data['transaction_details']['raw']['charge']['currency'] ??
                                     $output->data['transaction_details']['raw']['PAYMENTINFO_0_CURRENCYCODE'] ?? 
                                     '');
        $name = $output->data['transaction_details']['raw']['charge']['source']['name'] ??
                $output->data['transaction_details']['raw']['token']['card']['name'] ?? 
                $output->data['transaction_details']['checkout']['SHIPTONAME'] ?? 
                '';
        if (!empty($name)) {
            $output->receipt_name = $name;
            $split = explode(' ', $name);
            $output->receipt_first = array_shift($split);
            $output->receipt_last = array_pop($split);
        } elseif (!empty($output->data['transaction_details']['checkout']['FIRSTNAME'])) {
            $output->receipt_first = $output->data['transaction_details']['checkout']['FIRSTNAME'] ?? '';
            $output->receipt_last = $output->data['transaction_details']['checkout']['LASTNAME'] ?? '';
            $output->receipt_name = trim($output->receipt_first . ' ' . $output->receipt_name);
        }
            
		// Add in the email addresses to the top-level:
		$output->emails = array_values($emails);
		
		// Allow for a hook to create customized HTML output for things like Mandrill that aren't sophisticated enough yet:
		$output->customHTML = apply_filters( 'camptix_zapier_html', $output );
        
        // Send the data now to Zapier:
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']); 
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($output));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
        $output = curl_exec($ch);       
        curl_close($ch);
	}

    /**
     * New Menu Section
     **/
    function camptix_zapier_menu_sections($sections) {
	    $sections['zapier'] = "Zapier";
	    return $sections;
	}

    /**
     * Let's set up that menu for us!
     **/
    function camptix_zapier_menu_setup($section) {
        // *Shudders, global*
        global $camptix;
        
        if ($section == 'zapier') {
			add_settings_section( 'zapier', __( 'Zapier Configuration', 'camptix-zapier' ), function() {
			    echo '<p>' . __( 'Enter your custom Zapier webhook endpoints here to enable each type of ticket status to trigger a Zap for you:', 'camptix-zapier' ) . '</p>';
			}, 'camptix_options' );

		    foreach ($this->statii as $code => $desc) {
		        $camptix->add_settings_field_helper( "zapier_hook_{$code}", $desc, 'field_text', 'zapier' );
		    }
        }
    }
    
    /**
     * We need to validate & save those new options:
     **/
    function camptix_zapier_menu_validate($output, $input) {
	    foreach ($this->statii as $code => $desc) {
	        $key = "zapier_hook_{$code}";
	        if (!empty($input[$key]) && filter_var($input[$key], FILTER_VALIDATE_URL)) {
	            $output[$key] = $input[$key];
	        }
	    }
	    return $output;
	}

}
