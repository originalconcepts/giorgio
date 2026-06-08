<?php

use Carbon\Carbon;

defined( 'ABSPATH' ) || exit;

class OCWS_Deli_Shipping_Slots extends OC_Woo_Shipping_Slots {

    private $scheduling_type = 'weekly';

    private $date_format = 'd/m/Y';

    private $time_format = 'G:i';

    public function __construct($group_id) {

        parent::__construct(0);
        if (false !== $group_id) {
            $this->group_id = $group_id;
            $this->init_options();
        }
    }
}
