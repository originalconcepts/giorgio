<?php

use Carbon\Carbon;

defined( 'ABSPATH' ) || exit;

class OCWS_Deli_Menus {

    /* @var OCWS_Deli_Menu[] */
    private $all_menus = array();

    /**
     * The single instance of the class.
     *
     * @var OCWS_Deli_Menus
     */
    protected static $_instance = null;

    /**
     * Main OCWS_Deli_Menus Instance.
     *
     * Ensures only one instance of OCWS_Deli_Menus is loaded or can be loaded.
     *
     */
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function __construct() {

        $terms = get_terms( array(
            'taxonomy'   => array( 'product_menu' ),
            'hide_empty' => false,
            'fields'     => 'all'
        ) );

        if ( $terms ) {
            foreach ( $terms as $term ) {
                $this->all_menus[$term->term_id] = new OCWS_Deli_Menu($term);
            }
        }
    }

    /**
     * @param $product_id
     * @return int[]
     */
    public function find_product_menus( $product_id ) {
        $menus = array();
        //var_dump($this->all_menus);
        foreach ( $this->all_menus as $term_id => $menu ) {
            //error_log('menu id: '.$term_id ."; menu->is_product_assigned_by_category( $product_id )");
            //error_log($menu->is_product_assigned_by_category( $product_id )? 'assigned' : 'not assigned');
            if ( $menu->is_product_assigned_directly( $product_id ) /*|| $menu->is_product_assigned_by_category( $product_id )*/) {
                $menus[] = $term_id;
            }
        }
        return $menus;
    }

    public function find_product_dates( $product_id ) {
        //error_log("find_product_dates( $product_id )");
        $res = array('weekdays' => array(), 'dates' => array(), 'prep_days' => 0);
        $menus = $this->find_product_menus( $product_id );
        foreach ( $menus as $term_id ) {

            //error_log('menu id: '.$term_id);
            if (isset($this->all_menus[$term_id])) {
                $menu_weekdays = $this->all_menus[$term_id]->get_weekdays();
                foreach ($menu_weekdays as $wd) {
                    $res['weekdays'][] = $wd;
                }
                $menu_dates = $this->all_menus[$term_id]->get_dates();
                foreach ($menu_dates as $dt) {
                    $res['dates'][] = $dt;
                }
                //error_log('$menu_dates: '.print_r($menu_dates, 1));
                $prep_days = $this->all_menus[$term_id]->get_prep_days();
                if ($prep_days > $res['prep_days']) {
                    $res['prep_days'] = $prep_days;
                }
            }
        }
        $res['weekdays'] = array_unique($res['weekdays']);
        $res['dates'] = array_unique($res['dates']);
        return $res;
    }

    public function is_product_available_on_date( $product_id, $date, $slot_start='' ) {
        $product_menus = $this->find_product_menus( $product_id );
        /* If there are no menus the product is assigned to we consider the product as always available */
        if (empty( $product_menus )) {
            return true;
        }
        $available_menus = $this->find_menus_available_on_date( $date, $slot_start );
        foreach ( $product_menus as $term_id ) {
            if ( in_array($term_id, $available_menus) ) {
                return true;
            }
        }
        return false;
    }

    public function is_product_visible_on_date( $product_id, $date, $slot_start='' ) {
        $product_menus = $this->find_product_menus( $product_id );
        /* If there are no menus the product is assigned to we consider the product as always available */
        if (empty( $product_menus )) {
            return true;
        }
        if ( empty($date) ) {
            $visible_menus = $this->find_visible_menus_on_empty_date();
        } else {
            $visible_menus = $this->find_menus_visible_on_date($date, $slot_start);
        }
        foreach ( $product_menus as $term_id ) {
            if ( in_array($term_id, $visible_menus) ) {
                return true;
            }
        }
        return false;
    }

    public function find_menus_available_on_date( $date, $slot_start='' ) {
        $available_on_date_holiday = array();
        $available_on_date_not_holiday = array();
        foreach ( $this->all_menus as $term_id => $menu ) {
            if ( $menu->is_available_on_date( $date, $slot_start )) {
                if ( $menu->is_holiday() ) {
                    $available_on_date_holiday[] = $term_id;
                }
                else {
                    $available_on_date_not_holiday[] = $term_id;
                }
            }
        }
        return (!empty($available_on_date_holiday)? $available_on_date_holiday : $available_on_date_not_holiday);
    }

    public function find_menus_visible_on_date( $date, $slot_start='' ) {
        $available_on_date_holiday = array();
        $all_not_holiday = array();
        foreach ( $this->all_menus as $term_id => $menu ) {
            if ( $menu->is_available_on_date( $date, $slot_start )) {
                if ( $menu->is_holiday() ) {
                    $available_on_date_holiday[] = $term_id;
                }
            }
            if ( ! $menu->is_holiday() ) {
                $all_not_holiday[] = $term_id;
            }
        }
        if ( ! empty($available_on_date_holiday) ) {
            return $available_on_date_holiday;
        }
        return $all_not_holiday;
    }

    public function find_visible_menus_on_empty_date() {

        /* all days of week based menus */
        $available = array();
        foreach ( $this->all_menus as $term_id => $menu ) {
            if ( ! $menu->is_holiday() ) {
                $available[] = $term_id;
            }
        }
        return $available;
    }

    public function get_holidays() {
        $holidays = array();
        foreach ( $this->all_menus as $term_id => $menu ) {
            if ($menu->is_holiday()) {
                $menu_dates = $menu->get_dates();
                $holiday = $menu->get_title();
                foreach ($menu_dates as $dt) {
                    $holidays[] = array('date' => $dt, 'title' => $holiday);
                }
            }
        }
        return $holidays;
    }

    public function is_date_holiday($date) {

        foreach ( $this->all_menus as $term_id => $menu ) {
            if ($menu->is_holiday()) {
                $menu_dates = $menu->get_dates();
                foreach ($menu_dates as $dt) {
                    if ($dt == $date) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * @param WC_Product $product
     */
    public function handle_saved_product ( $product ) {

        if (!$product) {
            return;
        }
        $this->remove_all_menus_from_product( $product );
        foreach ( $this->all_menus as $term_id => $menu ) {
            $menu->handle_saved_product( $product );
        }
    }

    /**
     * @param WC_Product $product
     */
    public function remove_all_menus_from_product( $product ) {
        if (!$product) {
            return;
        }
        $terms = wc_get_product_terms( $product->get_id(), 'product_menu', array('fields' => 'all') );
        if ($terms) {
            foreach ($terms as $term) {
                wp_remove_object_terms($product->get_id(), $term->term_id, 'product_menu');
            }
        }
    }
}
