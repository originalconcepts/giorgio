<?php

defined( 'ABSPATH' ) || exit;

class OCWS_Cart_Item_Syncronizer {

    protected $from_blog_id;
    protected $to_blog_id;

    public function __construct($cart_item, $from_blog_id, $to_blog_id) {
        $this->from_blog_id = $from_blog_id;
        $this->to_blog_id = $to_blog_id;
    }
}