<?php
namespace AdobeHDS;

class cURL
{
    var $headers, $user_agent, $compression, $cookie_file;
    var $active, $cert_check, $fragProxy, $maxSpeed, $proxy, $response;
    var $mh, $ch, $mrc;
    static $ref = 0;

    function __construct($cookies = true, $cookie = 'Cookies.txt', $compression = 'gzip', $proxy = '')
    {
        $this->headers = $this->headers();
        $this->user_agent = 'Mozilla/5.0 (Windows NT 5.1; rv:26.0) Gecko/20100101 Firefox/26.0';
        $this->compression = $compression;
        $this->cookies = $cookies;
        if ($this->cookies == true)
            $this->cookie($cookie);
        $this->cert_check = false;
        $this->fragProxy = false;
        $this->maxSpeed = 0;
        $this->proxy = $proxy;
        self::$ref++;
    }

    function __destruct()
    {
        $this->stopDownloads();
        if ((self::$ref <= 1) and file_exists($this->cookie_file))
            unlink($this->cookie_file);
        self::$ref--;
    }

    function headers()
    {
        $headers[] = 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
        $headers[] = 'Connection: Keep-Alive';
        return $headers;
    }

    function cookie($cookie_file)
    {
        if (file_exists($cookie_file))
            $this->cookie_file = $cookie_file;
        else {
            $file = fopen($cookie_file, 'w') or $this->error('The cookie file could not be opened. Make sure this directory has the correct permissions.');
            $this->cookie_file = $cookie_file;
            fclose($file);
        }
    }

    function get($url)
    {
        $process = curl_init($url);
        $options = array(
            CURLOPT_HTTPHEADER => $this->headers,
            CURLOPT_HEADER => 0,
            CURLOPT_USERAGENT => $this->user_agent,
            CURLOPT_ENCODING => $this->compression,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_FOLLOWLOCATION => 1
        );
        curl_setopt_array($process, $options);
        if (!$this->cert_check)
            curl_setopt($process, CURLOPT_SSL_VERIFYPEER, false);
        if ($this->cookies == true) {
            curl_setopt($process, CURLOPT_COOKIEFILE, $this->cookie_file);
            curl_setopt($process, CURLOPT_COOKIEJAR, $this->cookie_file);
        }
        if ($this->proxy)
            $this->setProxy($process, $this->proxy);
        $this->response = curl_exec($process);
        if ($this->response !== false)
            $status = curl_getinfo($process, CURLINFO_HTTP_CODE);
        curl_close($process);
        if (isset($status))
            return $status;
        else
            return false;
    }

    function post($url, $data)
    {
        $process = curl_init($url);
        $headers = $this->headers;
        $headers[] = 'Content-Type: application/x-www-form-urlencoded;charset=UTF-8';
        $options = array(
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADER => 1,
            CURLOPT_USERAGENT => $this->user_agent,
            CURLOPT_ENCODING => $this->compression,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => $data
        );
        curl_setopt_array($process, $options);
        if (!$this->cert_check)
            curl_setopt($process, CURLOPT_SSL_VERIFYPEER, false);
        if ($this->cookies == true) {
            curl_setopt($process, CURLOPT_COOKIEFILE, $this->cookie_file);
            curl_setopt($process, CURLOPT_COOKIEJAR, $this->cookie_file);
        }
        if ($this->proxy)
            $this->setProxy($process, $this->proxy);
        $return = curl_exec($process);
        curl_close($process);
        return $return;
    }

    function setProxy(&$process, $proxy)
    {
        $type = "";
        $separator = strpos($proxy, "://");
        if ($separator !== false) {
            $type = strtolower(substr($proxy, 0, $separator));
            $proxy = substr($proxy, $separator + 3);
        }
        switch ($type) {
            case "socks4":
                $type = CURLPROXY_SOCKS4;
                break;
            case "socks5":
                $type = CURLPROXY_SOCKS5;
                break;
            default:
                $type = CURLPROXY_HTTP;
        }
        curl_setopt($process, CURLOPT_PROXY, $proxy);
        curl_setopt($process, CURLOPT_PROXYTYPE, $type);
    }

    function addDownload($url, $id)
    {
        if (!isset($this->mh))
            $this->mh = curl_multi_init();
        if (isset($this->ch[$id]))
            return false;
        $download =& $this->ch[$id];
        $download['id'] = $id;
        $download['url'] = $url;
        $download['ch'] = curl_init($url);
        $options = array(
            CURLOPT_HTTPHEADER => $this->headers,
            CURLOPT_HEADER => 0,
            CURLOPT_USERAGENT => $this->user_agent,
            CURLOPT_ENCODING => $this->compression,
            CURLOPT_LOW_SPEED_LIMIT => 1024,
            CURLOPT_LOW_SPEED_TIME => 10,
            CURLOPT_BINARYTRANSFER => 1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_FOLLOWLOCATION => 1
        );
        curl_setopt_array($download['ch'], $options);
        if (!$this->cert_check)
            curl_setopt($download['ch'], CURLOPT_SSL_VERIFYPEER, false);
        if ($this->cookies == true) {
            curl_setopt($download['ch'], CURLOPT_COOKIEFILE, $this->cookie_file);
            curl_setopt($download['ch'], CURLOPT_COOKIEJAR, $this->cookie_file);
        }
        if ($this->fragProxy and $this->proxy)
            $this->setProxy($download['ch'], $this->proxy);
        if ($this->maxSpeed > 0)
            curl_setopt($process, CURLOPT_MAX_RECV_SPEED_LARGE, $this->maxSpeed);
        curl_multi_add_handle($this->mh, $download['ch']);
        do {
            $this->mrc = curl_multi_exec($this->mh, $this->active);
        } while ($this->mrc == CURLM_CALL_MULTI_PERFORM);
        return true;
    }

    function checkDownloads()
    {
        if (isset($this->mh)) {
            curl_multi_select($this->mh);
            $this->mrc = curl_multi_exec($this->mh, $this->active);
            if ($this->mrc != CURLM_OK)
                return false;
            while ($info = curl_multi_info_read($this->mh)) {
                foreach ($this->ch as $download)
                    if ($download['ch'] == $info['handle'])
                        break;
                $array['id'] = $download['id'];
                $array['url'] = $download['url'];
                $info = curl_getinfo($download['ch']);
                if ($info['http_code'] == 0) {
                    /* if curl fails due to network connectivity issues or some other reason it's *
                     * better to add some delay before next try to avoid busy loop.               */
                    Utils::LogDebug("Fragment " . $download['id'] . ": " . curl_error($download['ch']));
                    usleep(1000000);
                    $array['status'] = false;
                    $array['response'] = "";
                } else if ($info['http_code'] == 200) {
                    if ($info['size_download'] >= $info['download_content_length']) {
                        $array['status'] = $info['http_code'];
                        $array['response'] = curl_multi_getcontent($download['ch']);
                    } else {
                        $array['status'] = false;
                        $array['response'] = "";
                    }
                } else {
                    $array['status'] = $info['http_code'];
                    $array['response'] = curl_multi_getcontent($download['ch']);
                }
                $downloads[] = $array;
                curl_multi_remove_handle($this->mh, $download['ch']);
                curl_close($download['ch']);
                unset($this->ch[$download['id']]);
            }
            if (isset($downloads) and (count($downloads) > 0))
                return $downloads;
        }
        return false;
    }

    function stopDownloads()
    {
        if (isset($this->mh)) {
            if (isset($this->ch)) {
                foreach ($this->ch as $download) {
                    curl_multi_remove_handle($this->mh, $download['ch']);
                    curl_close($download['ch']);
                }
                unset($this->ch);
            }
            curl_multi_close($this->mh);
            unset($this->mh);
        }
    }

    function error($error)
    {
        Utils::LogError("cURL Error : $error");
    }
}