<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class G2C_AddOn extends GFPaymentAddOn {

    protected $_version = "1.0";
    protected $_slug = "g2c";
    protected $_title = "Cardcom";
    protected $_short_title = "Cardcom";

    protected $_supports_feeds = true;

    private static $_instance = null;

    public static function get_instance() {
        if ( self::$_instance == null ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function init() {
        parent::init();
    }

    public function init_admin() {
        parent::init_admin();
    }

    public function feed_settings_title() {
        return 'Cardcom Settings';
    }

    public function feed_settings_fields() {
        return array(
            array(
                'title'  => 'Cardcom Feed',
                'fields' => array(
                    array(
                        'name'  => 'feed_name',
                        'label' => 'Feed Name',
                        'type'  => 'text',
                        'required' => true,
                    ),
                ),
            ),
        );
    }

    
}