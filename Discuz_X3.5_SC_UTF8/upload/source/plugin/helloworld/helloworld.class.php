<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }

/**
 * Front-end hook class for the "helloworld" plugin (module type 11).
 * Public methods named after Discuz hooks run at those hook points.
 * See: admin CP > Tools > "View available hooks" for the full list.
 */
class plugin_helloworld {
    // Appended to the bottom of every page (global_footer hook).
    public function global_footer() {
        return '<div style="text-align:center;padding:8px;color:#888;font-size:12px;">'
             . '[Hello World] plugin active'
             . '</div>';
    }
}
