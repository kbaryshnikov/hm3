<?php

function get_feed($tools, $url, $type, $limit, $feed_id, $ttl) {
    if ($ttl > 0) {
        $cache = hm_new('cache');
        $cache->ttl = $ttl;
        $data = $cache->get_feed($feed_id);
        if ($data) {
            return $data;
        }
    }
    $feed = hm_new('feed');
    $feed->limit = $limit;
    $feed->feed_type = $type;
    $feed->parse_feed($url);
    $cache = hm_new('cache');
    $cache->save_feed($feed_id, $feed->parsed_data);
    return $feed->parsed_data;
}

class Hm_Feed {
    var $url;
    var $id;
    var $xml_data;
    var $parsed_data;
    var $depth;
    var $type;
    var $limit;
    var $heading_block;
    var $data_block;
    var $update_cache;
    var $collect;
    var $item_count;
    var $refresh_cache;
    var $init_cache;
    var $cache_limit;
    var $sort;

    function __construct() {
        $this->sort = true;
        $this->limit = 5;
        $this->cache_limit = 0;
        $this->url = false;
        $this->xml_data = false;
        $this->id = 0;
        $this->parsed_data = array();
        $this->depth = 0;
        $this->feed_type = 'rss';
        $this->heading_block = false;
        $this->data_block = false;
        $this->collect = false;
        $this->refresh_cache = false;
        $this->update_cache = false;
        $this->init_cache = false;
        $this->item_count = 0;
    }
    function get_feed_data($url) {
        $buffer = '';
        if (!preg_match("?^http://?", ltrim($url))) {
            $url = 'http://'.ltrim($url);
        }
        if (function_exists('curl_setopt')) {
            $type = 'curl';
        }
        else {
            $type = 'file';
        }
        switch ($type) {
            case 'curl':
                $rand =  md5(uniqid(rand(), 1));
                $curl_handle=curl_init();
                curl_setopt($curl_handle, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.1) Gecko/20061204 Firefox/2.0.0.1");
                curl_setopt($curl_handle,CURLOPT_URL, $url);
                curl_setopt($curl_handle,CURLOPT_CONNECTTIMEOUT,15);
                curl_setopt($curl_handle,CURLOPT_RETURNTRANSFER,1);
                curl_setopt($curl_handle, CURLOPT_FOLLOWLOCATION, 1);
                curl_setopt($curl_handle, CURLOPT_COOKIEJAR, '/tmp/'.$rand.'.txt');
                curl_setopt($curl_handle, CURLOPT_COOKIEFILE, '/tmp/'.$rand.'.txt');
                $buffer = curl_exec($curl_handle);
                curl_close($curl_handle);
                unset($curl_handle);
                break;
            case 'file':
                $buffer = file_get_contents($url); 
                break;
        }
        $this->xml_data = $buffer;
        return $buffer;
    }
    function sort_by_time($a, $b) {
        if (!isset($a['pubdate']) || !isset($b['pubdate'])) {
            return 0;
        }
        $time1 = strtotime($a['pubdate']);
        $time2 = strtotime($b['pubdate']);
        if ($time1 == $time2) {
            return 0;
        }
        elseif ($time1 < $time2) {
            return 1;
        }
        else {
            return -1;
        }
    }
    function sort_parsed_data() {
        $data = $this->parsed_data;
        $title = array_shift($data);
        usort($data, array($this, 'sort_by_time'));
        $final_list = array();
        $i = 1;
        foreach ($data as $vals) {
            $final_list[] = $vals;
            if ($i == $this->limit) {
                break;
            }
            $i++;
        }
        array_unshift($final_list, $title);
        $this->parsed_data = $final_list;
    }
    function parse_feed($url) {
        global $dbwrap;
        $this->get_feed_data($url);
        if (!empty($this->parsed_data)) {
            return true;
        }
        $xml_parser = xml_parser_create();
        xml_set_object($xml_parser, $this);
        if ($this->feed_type == 'atom' || $this->feed_type == 'rss') {
            xml_set_element_handler($xml_parser, $this->feed_type.'_start_element', $this->feed_type.'_end_element');
            xml_set_character_data_handler($xml_parser, $this->feed_type.'_character_data');
            if  (xml_parse($xml_parser, $this->xml_data)) {
                xml_parser_free($xml_parser);
                if ($this->sort) {
                    $this->sort_parsed_data();
                }
                /* cache here ... */
                return true;
            }
            else {
                return false; 
            }
        }
        else {
            return false;
        }
    }
    /* ATOM FEED FUNCTIONS */
    function atom_start_element($parser, $tagname, $attrs) {
        if ($tagname == 'FEED') {
            $this->heading_block = true;
        }
        if ($tagname == 'ENTRY') {
            $this->heading_block = false;
            $this->item_count++;
            $this->data_block = true;
        }
        if ($this->data_block) {
            switch ($tagname) {
                case 'TITLE':
                case 'SUMMARY':
                case 'CONTENT':
                case 'GUID':
                case 'UPDATED':
                case 'MODIFIED':
                    $this->collect = strtolower($tagname);
                    break;
                case 'LINK':
                    if (isset($attrs['REL'])) {
                        $rel = $attrs['REL'];
                    }
                    else {
                        $rel = '';
                    }
                    $this->parsed_data[$this->item_count]['link_'.$rel] = $attrs['HREF'];
                    break;
            }
        }
        if ($this->heading_block) {
            switch ($tagname) {
                case 'TITLE':
                case 'UPDATED':
                case 'LANGUAGE':
                case 'ID':
                    $this->collect = strtolower($tagname);
                    break;
                case 'LINK':
                    if (isset($attrs['REL'])) {
                        $rel = $attrs['REL'];
                    }
                    else {
                        $rel = '';
                    }
                    $this->parsed_data[0]['link_'.$rel] = $attrs['HREF'];
                    break;
            }
        }
        $this->depth++;
    }
    function atom_end_element($parser, $tagname) {
        $this->collect = false;
        if ($tagname == 'ENTRY') {
            $this->data_block = false;
        }
        $this->depth--;
    }
    function atom_character_data($parser, $data) {
        if ($this->heading_block && $this->collect) {
            $this->parsed_data[0][$this->collect] = trim($data);
        }
        if ($this->data_block && $this->collect) {
            if ($this->collect == 'updated' || $this->collect == 'modified') {
                $this->collect = 'pubdate';
            }
            if (isset($this->parsed_data[$this->item_count][$this->collect])) {
                $this->parsed_data[$this->item_count][$this->collect] .= trim($data);
            }
            else {
                $this->parsed_data[$this->item_count][$this->collect] = trim($data);
            }
        }
    }
    /* RSS FEED FUNCTIONS */
    function rss_start_element($parser, $tagname, $attrs) {
        if ($tagname == 'CHANNEL') {
            $this->heading_block = true;
        }
        if ($tagname == 'ITEM') {
            $this->heading_block = false;
            $this->item_count++;
            $this->data_block = true;
        }
        if ($this->data_block) {
            switch ($tagname) {
                case 'TITLE':
                case 'LINK':
                case 'DESCRIPTION':
                case 'GUID':
                case 'PUBDATE':
                case 'DC:DATE':
                    $this->collect = strtolower($tagname);
                    break;
            }
        }
        if ($this->heading_block) {
            switch ($tagname) {
                case 'TITLE':
                case 'PUBDATE':
                case 'LANGUAGE':
                case 'DESCRIPTION':
                case 'LINK':
                    $this->collect = strtolower($tagname);
                    break;
                    
            }
        }
        $this->depth++;
    }
    function rss_end_element($parser, $tagname) {
        $this->collect = false;
        if ($tagname == 'ITEM') {
            $this->data_block = false;
        }
        $this->depth--;
    }
    function rss_character_data($parser, $data) {
        if ($this->heading_block && $this->collect) {
            $this->parsed_data[0][$this->collect] = $data;
        }
        if ($this->data_block && $this->collect) {
            if ($this->collect == 'dc:date') {
                $this->collect = 'pubdate';
            }
            if (isset($this->parsed_data[$this->item_count][$this->collect])) {
                $this->parsed_data[$this->item_count][$this->collect] .= trim($data);
            }
            else {
                $this->parsed_data[$this->item_count][$this->collect] = trim($data);
            }

        }
    }
}
class cache {
    var $ttl;
    function cache() {
        $this->ttl = 300;
        if (!isset($_SESSION['news_cache'])) {
            $_SESSION['news_cache'] = array();
        }
    }
    function save_feed($id, $data) {
        $_SESSION['news_cache'][$id] = array(time(), $data);
    }
    function get_feed($id) {
        if (isset($_SESSION['news_cache'][$id])) {
            $start_time = $_SESSION['news_cache'][$id][0];
            $diff = time() - $start_time;
            if ($diff < $this->ttl) {
                return $_SESSION['news_cache'][$id][1];
            }
        }
        return false;
    }
}

?>