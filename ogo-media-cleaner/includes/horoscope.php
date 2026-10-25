<?php
/** Compatibility entry point for the horoscope URL operation. */
require_once __DIR__.'/rubric.php';
final class OGO_MC_Horoscope {
    public static function analyze($content) {
        return OGO_MC_Rubric::analyze($content, 'goroskop');
    }
}
