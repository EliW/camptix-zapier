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
	    // Register the action handler for us that will receive (and send) the data:
		add_action( 'camptix_payment_result', [ $this, 'camptix_zapier_action' ], 10, 3 );

        // Set up a new menu of config for us:
		add_filter( 'camptix_setup_sections', [ $this, 'camptix_zapier_menu_sections' ], 10, 1);
		add_action( 'camptix_menu_setup_controls', [ $this, 'camptix_zapier_menu_setup' ], 10, 1 );
		add_filter( 'camptix_validate_options', [ $this, 'camptix_zapier_menu_validate' ], 10, 2 );

        // Debugging:
        // $this->camptix_zapier_action("15b2df571e9f7253cb5ceb05f8ac05f3", 2, []);
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
			$this->log( 'Could not find attendees by payment token', null, $_POST );
			die();
		}

        // Loop through all Attendees/tickets now:
        $output->attendees = [];
        foreach ($attendees as $k => $att) {
            // Prep a storage class:
            $ticket = new StdClass;
            
            // Get the raw meta data:
            //$meta = get_post_meta($att->ID, '');
            
            // Pick up data that we care about:
            foreach (['tix_access_token', 'tix_payment_token', 'tix_edit_token', 'tix_payment_method', 'tix_timestamp',
                      'tix_first_name', 'tix_last_name', 'tix_email', 'tix_ticket_price', 'tix_ticket_discounted_price',
                      'tix_order_total', 'tix_coupon', 'tix_transaction_id', 'tix_transaction_details'] as $midx) {
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

    		// Add the ticket to the output:
    		$output->attendees[] = json_encode($ticket);
    		
    		// If this is the first one, save a few items to the top level so we have some data to work with in Zapier:
    		if (!$k) {
    		    $output->coupon = $ticket->tix_coupon;
    		    $output->total = $ticket->tix_order_total;
    		    $output->payment_method = $ticket->tix_payment_method;
    		    $output->default_first_name = $ticket->tix_first_name;
    		    $output->default_last_name = $ticket->tix_last_name;
    		}
		}

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
