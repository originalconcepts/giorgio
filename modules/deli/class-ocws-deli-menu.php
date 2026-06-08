<?php

use Carbon\Carbon;

defined( 'ABSPATH' ) || exit;

class OCWS_Deli_Menu {

    private $term;
    private $title = '';
    private $availability_type = 'weekdays';
    private $weekdays = array();
    private $dates = array();
    private $categories = array();
    private $excluded_products = array();
    private $prep_days_num = 0;

    private $date_format = 'd/m/Y';
    private $time_format = 'G:i';
    protected $tz = '';
    protected $default_max_hour = '23:59';

    public function __construct($term) {

        $this->term = $term;
        $this->title = $term->name;
        $availability_type = get_term_meta( $term->term_id, 'availability_type', true );
        $this->availability_type = $availability_type? $availability_type : 'weekdays';
        if ($this->availability_type == 'weekdays') {
            $weekdays = get_term_meta( $term->term_id, 'weekdays', true );
            //echo 'weekdays: '.$weekdays;
            if (!$weekdays && $weekdays !== 0 && $weekdays !== '0') {
                $this->weekdays = array();
            }
            else {
                $this->weekdays = array_map( 'intval', ocws_numbers_list_to_array( $weekdays ) );
            }
        }
        else {
            $dates = get_term_meta( $term->term_id, 'dates', true );
            if (!$dates) {
                $this->dates = array();
            }
            else {
                $this->dates = ocws_dates_list_to_array( $dates, true );
            }
        }
        $categories = get_term_meta( $term->term_id, 'categories', true );
        if (!$categories) {
            $this->categories = array();
        }
        else {
            $this->categories = array_map( 'intval', ocws_numbers_list_to_array( $categories ) );
        }
        $cat_products = get_term_meta( $term->term_id, 'products', true );
        $this->excluded_products = array();
        if ($cat_products) {
            $products_raw = explode(';', $cat_products);
            foreach ($products_raw as $catprods) {
                if (!empty($catprods)) {
                    $res = explode(':', $catprods, 2);
                    if (count($res) == 2) {
                        $this->excluded_products[intval($res[0])] = ocws_numbers_list_to_array($res[1]);
                    }
                }
            }
        }
        $prep_days = get_term_meta( $term->term_id, 'prep_days', true );
        if (is_numeric($prep_days)) {
            $this->prep_days_num = intval($prep_days);
        }
    }

    public function get_title() {
        return $this->title;
    }

    public function is_product_assigned_directly($product_id) {
        $terms = wc_get_product_terms( $product_id, 'product_menu', array('fields' => 'all') );
        if (!$terms) {
            return false;
        }
        foreach ($terms as $term) {
            if ($term->term_id === $this->term->term_id) {
                return true;
            }
        }
        return false;
    }

    public function is_category_assigned($category_id) {
        return in_array($category_id, $this->categories);
    }

    public function is_product_assigned_by_category($product_id) {
        $terms = wc_get_product_terms( $product_id, 'product_cat', array('fields' => 'all') );
        $assigned = false;
        if ($terms) {
            foreach ($terms as $term) {
                if ( $this->is_category_assigned( $term->term_id ) ) {
                    $assigned = true;
                    break;
                }
            }
        }
        //error_log("is_product_assigned_by_category($product_id)");
        //error_log('assigned: '. ($assigned? 'true' : 'false'));
        //error_log('prod excluded: '. ($this->is_product_excluded_from_menu($product_id)? 'true' : 'false'));
        return ($assigned && (! $this->is_product_excluded_from_menu($product_id)));
    }

    public function is_product_excluded($product_id, $cat_id) {

        if ( !isset( $this->excluded_products[$cat_id] ) ) {
            return false;
        }
        return in_array($product_id, $this->excluded_products[$cat_id]);
    }

    public function is_product_excluded_from_menu($product_id) {

        //error_log("is_product_excluded_from_menu($product_id)");
        //error_log(print_r($this->excluded_products, 1));
        foreach ( $this->excluded_products as $cat_id => $list ) {
            if ( in_array( $product_id, $list ) ) {
                //error_log('product id: '.$product_id);
                //error_log( print_r($list, 1) );
                return true;
            }
        }
        return false;
    }

    public function is_available_on_date($date, $slot_start='') {
        $available = false;
        if ($this->availability_type == 'dates') {
            if ( in_array($date, $this->dates) ) {
                $available = true;
            }
        }
        else {
            try {
                $dt = Carbon::createFromFormat($this->date_format, $date, $this->get_timezone());
                if ( in_array($dt->dayOfWeek, $this->weekdays) ) {
                    $available = true;
                }
            }
            catch (InvalidArgumentException $e) {
                return false;
            }
        }
        return ( $available && $this->enough_days_to_prepare($date, $slot_start) );
    }

    protected function enough_days_to_prepare($date, $max_hour='') {
        if (!$this->prep_days_num) {
            return true;
        }
        try {
            $now = Carbon::now($this->get_timezone());
            $dt = Carbon::createFromFormat($this->date_format, $date, $this->get_timezone());
            if (empty($max_hour)) {
                $max_hour = $this->default_max_hour;
            }

            $pDay = clone $dt;
            $pDay->subDays( $this->prep_days_num );

            // get last date and time to order for $date
            $max = Carbon::createFromFormat(
                sprintf('%s %s', $this->date_format, $this->time_format),
                sprintf('%s %s', $pDay->format($this->date_format), $max_hour),
                $this->get_timezone()
            );

            if ($now->greaterThanOrEqualTo($max)) {
                return false;
            }
        }
        catch (InvalidArgumentException $e) {
            return false;
        }
        return true;
    }

    public function is_holiday() {
        return ($this->availability_type == 'dates');
    }

    public function get_weekdays() {
        if ($this->availability_type == 'weekdays') {
            return $this->weekdays;
        }
        return array();
    }

    public function get_dates() {
        if ($this->availability_type == 'dates') {
            return $this->dates;
        }
        return array();
    }

    public function get_prep_days() {
        return $this->prep_days_num;
    }

    /**
     * @param WC_Product $product
     */
    public function handle_saved_product( $product ) {
        if ($this->is_product_assigned_by_category( $product->get_id()) ) {
            $this->assign_product_directly( $product->get_id() );
        }
        else {
            $this->unassign_product_directly( $product );
        }
    }

    public function assign_product_directly( $product_id ) {
        wp_set_object_terms($product_id, $this->term->term_id, 'product_menu');
    }

    public function unassign_product_directly( $product_id ) {
        wp_remove_object_terms($product_id, $this->term->term_id, 'product_menu');
    }

    protected function get_timezone() {
        if (empty($this->tz)) {
            $this->tz = ocws_get_timezone();
        }
        return $this->tz;
    }
}
