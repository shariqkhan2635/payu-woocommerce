<?php

/**
 *
 * This class defines all code necessary to run during the payu's activation.
 * 
 **/
class PayuActivator
{

    public static function activate()
    {

        global $table_prefix, $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $payu_transactions_tblname = 'payu_transactions';
        $wp_track_payu_transactions_tblname = $table_prefix . "$payu_transactions_tblname ";

        #Check to see if the table exists already, if not, then create it
        require_once(ABSPATH . '/wp-admin/includes/upgrade.php');
        if ($wpdb->get_var("show tables like '$wp_track_payu_transactions_tblname'") != $wp_track_payu_transactions_tblname) {

            $payu_transactions_create_query = "CREATE TABLE $wp_track_payu_transactions_tblname (
                id int(11) NOT NULL auto_increment,
                transaction_id varchar(200) NOT NULL,
                order_id int(20) NOT NULL,
                payu_response text NOT NULL,
                status varchar(50) NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY id (id)
        ) $charset_collate;";

            dbDelta($payu_transactions_create_query);
        }

        $payu_refund_transactions_tblname = 'payu_refund_transactions';
        $wp_track_payu_refund_transactions_tblname = $table_prefix . "$payu_refund_transactions_tblname ";
        $charset_collate = $wpdb->get_charset_collate();
        #Check to see if the table exists already, if not, then create it

        if ($wpdb->get_var("show tables like '$wp_track_payu_refund_transactions_tblname'") != $wp_track_payu_refund_transactions_tblname) {

            $refund_transactions_query = "CREATE TABLE $wp_track_payu_refund_transactions_tblname (
                id int(11) NOT NULL auto_increment,
                request_id varchar(200) DEFAULT NULL,
                order_id int(20) NOT NULL,
                refund_type enum('full','partial') default 'full',
                items text NOT NULL,
                payu_response text NOT NULL,
                status varchar(50) NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY id (id)
        ) $charset_collate;";

            dbDelta($refund_transactions_query);
        }

        $payu_refund_transactions_tblname = 'payu_event_logs';
        $wp_track_payu_refund_transactions_tblname = $table_prefix . "$payu_refund_transactions_tblname ";
        $charset_collate = $wpdb->get_charset_collate();
        #Check to see if the table exists already, if not, then create it

        if ($wpdb->get_var("show tables like '$wp_track_payu_refund_transactions_tblname'") != $wp_track_payu_refund_transactions_tblname) {

            $refund_transactions_query = "CREATE TABLE $wp_track_payu_refund_transactions_tblname (
                log_id int(11) NOT NULL auto_increment,
                request_type varchar(20) NOT NULL,
                request_method varchar(20) NOT NULL,
                request_url varchar(150) NOT NULL,
                request_headers text NOT NULL,
                request_data text NOT NULL,
                response_status int(11) NOT NULL,
                response_headers text DEFAULT NULL,
                response_data text DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY log_id (log_id)
        ) $charset_collate;";

            dbDelta($refund_transactions_query);
        }

        $payu_payment_verification_log_tblname = 'payu_cron_logs';
        $wp_track_payu_payment_verification_log_tblname = $table_prefix . "$payu_payment_verification_log_tblname ";
        $charset_collate = $wpdb->get_charset_collate();

        #Check to see if the table exists already, if not, then create it
        if ($wpdb->get_var("show tables like '$wp_track_payu_payment_verification_log_tblname'") != $wp_track_payu_payment_verification_log_tblname) {

            $verify_payment_log_query = "CREATE TABLE $wp_track_payu_payment_verification_log_tblname (
                id int(11) NOT NULL auto_increment,
                order_id int(20) NOT NULL,
                transaction_id varchar(255) DEFAULT NULL,
                order_status varchar(255) DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY id (id)
        ) $charset_collate;";

            dbDelta($verify_payment_log_query);
        }

        $payu_payment_verification_log_tblname = 'payu_address_sync';
        $wp_track_payu_payment_verification_log_tblname = $table_prefix . "$payu_payment_verification_log_tblname ";
        $charset_collate = $wpdb->get_charset_collate();

        #Check to see if the table exists already, if not, then create it
        if ($wpdb->get_var("show tables like '$wp_track_payu_payment_verification_log_tblname'") != $wp_track_payu_payment_verification_log_tblname) {

            $verify_payment_log_query = "CREATE TABLE $wp_track_payu_payment_verification_log_tblname (
                id int(11) NOT NULL auto_increment,
                user_id int(20) NOT NULL,
                payu_address_id int(20) NOT NULL,
                payu_user_id int(20) NOT NULL,
                address_type varchar(255) DEFAULT 'shipping',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY id (id)
        ) $charset_collate;";

            dbDelta($verify_payment_log_query);
        }

        create_user_and_login_if_not_exist(PAYU_USER_TOKEN_EMAIL,false); // create payu user to generate auth token

    }
}
