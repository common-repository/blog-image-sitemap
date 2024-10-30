<?php

namespace ImageSitemap;

use WPSEO_Meta;

class CronScheduleAction
{
    private $siteUrl;
    private $defaultFileName = 'sitemap-images.xml';
    private $optionName = '5ba4c23331acf0c1249786f43138a12b'; // md5('prepareImagesSitemap')

    public function __construct()
    {
        $this->siteUrl = site_url();
        if (in_array($this->siteUrl, ['http:', 'https:'])) {
            $this->siteUrl = get_option($this->optionName);
        } else {
            $this->siteUrl = site_url() . '/';
            update_option($this->optionName, $this->siteUrl);
        }

        add_action('prepareImagesSitemap', [$this, 'hookHandle']);
        if (!wp_next_scheduled('prepareImagesSitemap')) {
            wp_schedule_event(strtotime('00:00:00'), 'daily', 'prepareImagesSitemap');
        }
    }

    public function hookHandle()
    {
        echo 'prepareImagesSitemap hook start';
        if (!function_exists('get_supercache_dir')) {
            wp_die();
        }

        $pluginOptions = get_option(AdminSettingsPage::getPageSettingOptionName());
        $fileName = $pluginOptions[AdminSettingsPage::getFileNameOption()] ?? $this->defaultFileName;
        if (!$fileName) {
            $fileName = $this->defaultFileName;
        }

        $pagesListMapping = $this->getPagesListMapping();

        $amountSitemapPages = 0;
        $baseCacheFileTableContent = '';

        foreach ($pagesListMapping as $lang => $pagesList) {
            $langCacheFileName = str_replace('.xml', "-{$lang}.xml", $fileName);

            $langImagesSitemap = $this->handleSitemapImages($pagesList);

            if (!$langImagesSitemap) {
                $langCacheFileAbsCache = $this->getAbsFilePath($langCacheFileName);
                if (file_exists($langCacheFileAbsCache)) {
                    $amountSitemapPages++;
                    $baseCacheFileTableContent .= $this->generateTableRowData($langCacheFileName);
                }
                continue;
            }

            $langCacheFileAbs = $this->getAbsFilePathAndOldDelete($langCacheFileName);
            $amountSitemapPages++;
            $baseCacheFileTableContent .= $this->generateTableRowData($langCacheFileName);

            file_put_contents($langCacheFileAbs, $langImagesSitemap);
        }

        $baseCacheFileAbs = $this->getAbsFilePathAndOldDelete($fileName);
        if (!$amountSitemapPages) {
            echo 'Page not found in ' . get_supercache_dir() . ' dir';
            return false;
        }
        $baseCacheFileContent = file_get_contents(plugin_dir_path(IMAGE_SITEMAP_INDEX_FILE) . 'inc/sitemap-images-example.xml');
        $baseCacheFileContent = str_replace('%sitemap_rows%', $baseCacheFileTableContent, $baseCacheFileContent);

        file_put_contents($baseCacheFileAbs, $baseCacheFileContent);
        return true;
    }

    /**
     * https://cpanelplesk.com/get-list-wordpress-post-urls/
     * https://wpml.org/ru/forums/topic/%D0%B2%D1%8B%D0%B2%D0%BE%D0%B4-%D1%80%D0%B0%D0%B7%D0%BD%D1%8B%D1%85-%D1%81%D1%82%D0%B0%D1%82%D0%B5%D0%B9-%D0%BF%D0%BE%D1%81%D1%82%D0%BE%D0%B2-%D0%BD%D0%B0-%D1%80%D0%B0%D0%B7%D0%BD%D1%8B%D1%85-%D1%8F/
     * @return array
     */
    private function getPagesListMapping()
    {
        global $sitepress;
        $mapping = [];

        if (!$sitepress) {
            return $mapping;
        }

        $langs = array_keys($sitepress->get_active_languages(false, true, 'id') ?? []);
        foreach ($langs as $lang) {
            $langMap = [];
            $sitepress->switch_lang($lang);

            $posts = query_posts([
                'post_type' => 'page',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'suppress_filters' => false
            ]);

            foreach ($posts as $post) {
                $noIndex = WPSEO_Meta::get_value('meta-robots-noindex', $post->ID);
                if ($noIndex == true) {
                    continue;
                }

                array_push($langMap, $this->preparePageAddress(get_page_link($post->ID), $lang));
            }

            $mapping[$lang] = $langMap;
        }

        return $mapping;
    }

    private function preparePageAddress($pagePath, $lang)
    {
        if (strpos($pagePath, '.html') !== false) {
            return $pagePath;
        }

        if ($pagePath == $this->siteUrl . $lang . '/') {
            return $pagePath;
        }

        if (substr($pagePath, -1, 1) == '/') {
            $pagePath = substr_replace($pagePath, '', -1);
        }

        return $pagePath . '/' == $this->siteUrl ? $this->siteUrl : $pagePath . '.html';
    }

    private function getAbsFilePathAndOldDelete(string $fileName)
    {
        $fileAbsPath = $this->getAbsFilePath($fileName);
        if (!file_exists($fileAbsPath)) {
            return $fileAbsPath;
        }

        unlink($fileAbsPath);
        return $fileAbsPath;
    }

    private function getAbsFilePath(string $fileName)
    {
        return get_home_path() . $fileName;
    }

    private function handleSitemapImages(array $pagesList)
    {
        if (!function_exists('str_get_html')) {
            require_once plugin_dir_path(IMAGE_SITEMAP_INDEX_FILE) . 'lib/simple_html_dom.php';
        }

        $urlXml = null;

        foreach ($pagesList as $pageUrlAddress) {
            $content = $this->getPageContentByUrl($pageUrlAddress, true);
            if (!$content) {
                $content = $this->getPageContentByUrl($pageUrlAddress, false);
                if (!$content) {
                    continue;
                }
            }

            $html = str_get_html($content);
            $aPics = $html->find('img');
            if (!$aPics) {
                unset($html);
                continue;
            }

            $imagesXml = '';

            foreach ($aPics as $element) {

                $imgSrc = $element->src;
                $imgDataOriginal = $element->getAttribute('data-original');
                $imgAlt = htmlspecialchars($element->alt);
                $imgTitle = htmlspecialchars($element->title);
                if (!$imgTitle) {
                    $imgTitle = $imgAlt;
                }

                if ($imgDataOriginal && stristr($imgDataOriginal, '?') === false && $imgDataOriginal != $this->siteUrl) {
                    $imgSrc = $imgDataOriginal;
                }

                if ($imgSrc && stristr($imgSrc, '?') === false) {
                    $imgSrc = str_ireplace($this->siteUrl, '', $imgSrc);
                    $imgHost = strtolower(parse_url($imgSrc, PHP_URL_HOST));
                    if ($imgHost == '') {
                        $imgSrc = $this->siteUrl . $imgSrc;
                        $imgSrc = preg_replace('#(\.\./)+#', '/', $imgSrc);
                        $imagesXml .= sprintf($this->imageXmlPattern(), $imgSrc, $imgTitle, $imgAlt);
                    }
                }
            }

            $urlXml .= sprintf($this->getUrlXmlPattern(), $pageUrlAddress, $imagesXml);
            unset($html);
        }

        return $urlXml ? sprintf($this->getUrlSetXmlPattern(), $urlXml) : null;
    }

    private function imageXmlPattern()
    {
        return
            '<image:image>' . "\n" .
            '<image:loc>%s</image:loc>' . "\n" .
            '<image:title>%s</image:title>' . "\n" .
            '<image:caption>%s</image:caption>' . "\n" .
            '</image:image>' . "\n";
    }

    private function getUrlSetXmlPattern()
    {
        return
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
            '<urlset xmlns="https://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="https://www.google.com/schemas/sitemap-image/1.1">' . "\n" .
            '%s' . "\n" .
            '</urlset>';
    }

    private function getUrlXmlPattern()
    {
        return
            '<url>' . "\n" .
            '<loc>%s</loc>' . "\n" .
            '%s' . "\n" .
            '</url>' . "\n";
    }

    private function getPageContentByUrl($pageUrlAddress, $https)
    {
        $superCacheDir = get_supercache_dir();
        $pageCachePath = str_replace($this->siteUrl, $superCacheDir, $pageUrlAddress);
        $pageCachePath .= $https ? '/index-https.html' : '/index.html';

        if (!file_exists($pageCachePath)) {
            return false;
        }

        $content = file_get_contents($pageCachePath);
        return mb_convert_encoding($content, 'utf-8', mb_detect_encoding($content));
    }

    private function generateTableRowData(string $fileName)
    {
        return sprintf(
            '%3$s<sitemap>%4$s<loc>%1$s</loc>%4$s<lastmod>%2$s</lastmod>%3$s</sitemap>',
            $this->siteUrl . $fileName,
            date('c'),
            "\n\t",
            "\n\t\t"
        );
    }
}