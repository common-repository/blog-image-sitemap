<?php
/*
Plugin Name: Blog Image Sitemap
Description: Create sitemap for blog pictures with cron schedule
Author: Evgeniy Kozenok
Version: 0.2.7
*/

include_once __DIR__ . '/vendor/autoload.php';

use ImageSitemap\AdminSettingsPage;
use ImageSitemap\CronScheduleAction;

if (!defined('IMAGE_SITEMAP_INDEX_FILE')) {
    define('IMAGE_SITEMAP_INDEX_FILE', __FILE__);
}

new CronScheduleAction();

AdminSettingsPage::init();