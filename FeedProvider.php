<?php

namespace Neochic\FeedProvider;

use SimplePie;

class FeedProvider {
    public function __construct() {
        $this->attachToWoodlets();
    }

    public function getFeed($url, $amount = 3) {
        $simplePie = new SimplePie();
        $simplePie->enable_cache(true);
        $simplePie->set_cache_location($this->getTmpDir());
        $simplePie->set_feed_url($url);
        $simplePie->init();
        return $simplePie->get_items(0, $amount);
    }

    public function getFacebookFeed($fbProfileId, $appId, $appSecret, $amount = 3, $debug = false, $cache = true) {
        $cacheFile = $this->getTmpDir() . '/ncfp_facebook_feed_' . $appId . '.json';
        if ($cache && is_file($cacheFile)) {
            $data = json_decode($this->fileGetContentsCurl($cacheFile, array(), $debug));
            if ($data->timestamp > time() - 3600) {
                return $data->stream;
            }
        }

        $facebookFeed = null;
        $token = "access_token=" . $appId . "|" . $appSecret;

        try {
            $url = "https://graph.facebook.com/" . $fbProfileId . "/posts?". $token . "&fields=created_time,message,from,to,link,object_id,status_type,picture,full_picture";
            ini_set('user_agent', 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.9) Gecko/20071025 Firefox/2.0.0.9');
            $facebookFeed = json_decode($this->fileGetContentsCurl($url, array(), $debug));

            if ($facebookFeed) {
                $facebookFeed = array_filter($facebookFeed->data, function ($e) use ($fbProfileId) {
                    $valid = in_array($e->status_type, array('added_photos', 'shared_story', 'added_video'));
                    $valid = $valid && $e->from->id === $fbProfileId;
                    $valid = $valid && isset($e->message);
                    return $valid;
                });

                $facebookFeed = array_splice($facebookFeed, 0, $amount);

                foreach($facebookFeed as $key => &$item) {
                    $prefix = $fbProfileId.'_';
                    $postId = $item->id;

                    if (substr($postId, 0, strlen($prefix)) == $prefix) {
                        $postId = substr($postId, strlen($prefix));
                    }

                    $item->link = "https://www.facebook.com/".$fbProfileId."/posts/".$postId;

                    if (isset($item->object_id)) {
                        $url = "https://graph.facebook.com/" . $item->object_id . "?". $token . "&fields=format";
                        $objectFormatFeed = json_decode($this->fileGetContentsCurl($url));
                        if ($objectFormatFeed && !empty($objectFormatFeed->format)) {
                            $best = null;
                            foreach ($objectFormatFeed->format as $format) {
                                if($best === null) {
                                    $best = $format;
                                } else if($format->width > $best->width && $format->width < 800){ // 800px maxwidth for images
                                    $best = $format;
                                }
                            }
                            if ($best) {
                                $item->picture = $best->picture;
                                if ($best->embed_html) {
                                    $item->embed_html = $best->embed_html;
                                }
                                continue;
                            }
                        }
                    }

                    // what's this :F ??
                    if(isset($item->picture)) {
                        $url = parse_url($item->picture);
                        parse_str($url["query"], $query);
                        if (isset($query["url"]) && strpos($query["url"], 'http') === 0) {
                            $item->picture = $query["url"];
                        }
                    }
                }

                $data = array(
                    "timestamp" => time(),
                    "stream" => $facebookFeed
                );
                file_put_contents($cacheFile, json_encode($data));
            }
        } catch (\Exception $e) {
            $facebookFeed = array();
        }

        return $facebookFeed;
    }

    protected function getTmpDir()
    {
        if ($this->withinWordpressWoodletsContext()) {
            return get_temp_dir();
        }
        return sys_get_temp_dir();
    }

    protected function fileGetContentsCurl($url, $curlopt = array(), $debug = false)
    {
        $ch = curl_init();
        $default_curlopt = array(
            CURLOPT_TIMEOUT => 20, //timeout after 20 secs ..
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_SSL_VERIFYPEER => $debug ? 0 : 1,
            CURLOPT_SSL_VERIFYHOST => $debug ? 0 : 1,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.9) Gecko/20071025 Firefox/2.0.0.9'
        );
        $curlopt = array(CURLOPT_URL => $url) + $curlopt + $default_curlopt;
        curl_setopt_array($ch, $curlopt);
        $response = curl_exec($ch);
        if ($response === false) {
            trigger_error(curl_error($ch));
        }
        curl_close($ch);
        return $response;
    }

    protected function withinWordpressWoodletsContext()
    {
        return class_exists ("Neochic\\Woodlets\\Woodlets");
    }

    protected function attachToWoodlets() {
        if ($this->withinWordpressWoodletsContext()) {
            add_filter('neochic_woodlets_twig', function($twig) {
                $twig->addFunction(new \Twig_SimpleFunction('getFeed', function ($url, $amount = 3) {
                    return $this->getFeed($url, $amount);
                }));
                $twig->addFunction(new \Twig_SimpleFunction('getFacebookFeed', function ($fbProfileId, $appId, $appSecret, $amount = 3, $debug = false, $cache = true) {
                    return $this->getFacebookFeed($fbProfileId, $appId, $appSecret, $amount, $debug, $cache);
                }));
                return $twig;
            });
        }
    }
}
