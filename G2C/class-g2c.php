<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class G2C_AddOn extends GFPaymentAddOn {
    

    protected $_version = "1.0";
    protected $_min_gravityforms_version = "2.5";
    protected $_slug = "g2c";

    // 🔥 זה היה גורם לקריסה קודם
    protected $_path = '';
    protected $_full_path = '';

    protected $_title = "Cardcom Add-On";
    protected $_short_title = "Cardcom";

    private static $_instance = null;

    public static function get_instance() {
        if ( self::$_instance == null ) {
            self::$_instance = new G2C_AddOn();
        }
        return self::$_instance;
    }

    public function init() {
        parent::init();
    }

    public function feed_settings_fields() {
        return array(
            array(
                'title'  => 'Cardcom Feed',
                'fields' => array(
                    array(
                        'label' => 'Feed Name',
                        'type'  => 'text',
                        'name'  => 'feed_name',
                        'required' => true,
                    ),
                ),
            ),
        );
    }

}