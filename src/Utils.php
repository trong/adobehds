<?php
namespace AdobeHDS;

class Utils
{
    public static $showHeader = false;
    public static $quiet = false;
    public static $debug = false;

    public static function ReadByte($str, $pos)
    {
        $int = unpack('C', $str[$pos]);
        return $int[1];
    }

    public static function ReadInt24($str, $pos)
    {
        $int32 = unpack('N', "\x00" . substr($str, $pos, 3));
        return $int32[1];
    }

    public static function ReadInt32($str, $pos)
    {
        $int32 = unpack('N', substr($str, $pos, 4));
        return $int32[1];
    }

    public static function ReadInt64($str, $pos)
    {
        $hi = sprintf("%u", self::ReadInt32($str, $pos));
        $lo = sprintf("%u", self::ReadInt32($str, $pos + 4));
        $int64 = bcadd(bcmul($hi, "4294967296"), $lo);
        return $int64;
    }

    public static function ReadString($str, &$pos)
    {
        $len = 0;
        while ($str[$pos + $len] != "\x00")
            $len++;
        $str = substr($str, $pos, $len);
        $pos += $len + 1;
        return $str;
    }

    public static function ReadBoxHeader($str, &$pos, &$boxType, &$boxSize)
    {
        if (!isset($pos))
            $pos = 0;
        $boxSize = self::ReadInt32($str, $pos);
        $boxType = substr($str, $pos + 4, 4);
        if ($boxSize == 1) {
            $boxSize = self::ReadInt64($str, $pos + 8) - 16;
            $pos += 16;
        } else {
            $boxSize -= 8;
            $pos += 8;
        }
        if ($boxSize <= 0)
            $boxSize = 0;
    }

    public static function WriteByte(&$str, $pos, $int)
    {
        $str[$pos] = pack('C', $int);
    }

    public static function WriteInt24(&$str, $pos, $int)
    {
        $str[$pos] = pack('C', ($int & 0xFF0000) >> 16);
        $str[$pos + 1] = pack('C', ($int & 0xFF00) >> 8);
        $str[$pos + 2] = pack('C', $int & 0xFF);
    }

    public static function WriteInt32(&$str, $pos, $int)
    {
        $str[$pos] = pack('C', ($int & 0xFF000000) >> 24);
        $str[$pos + 1] = pack('C', ($int & 0xFF0000) >> 16);
        $str[$pos + 2] = pack('C', ($int & 0xFF00) >> 8);
        $str[$pos + 3] = pack('C', $int & 0xFF);
    }

    public static function WriteBoxSize(&$str, $pos, $type, $size)
    {
        if (substr($str, $pos - 4, 4) == $type)
            self::WriteInt32($str, $pos - 8, $size);
        else {
            self::WriteInt32($str, $pos - 8, 0);
            self::WriteInt32($str, $pos - 4, $size);
        }
    }

    public static function WriteFlvTimestamp(&$frag, $fragPos, $packetTS)
    {
        self::WriteInt24($frag, $fragPos + 4, ($packetTS & 0x00FFFFFF));
        self::WriteByte($frag, $fragPos + 7, ($packetTS & 0xFF000000) >> 24);
    }

    public static function AbsoluteUrl($baseUrl, $url)
    {
        if (!self::isHttpUrl($url))
            $url = self::JoinUrl($baseUrl, $url);
        return self::NormalizePath($url);
    }

    public static function GetString($object)
    {
        return trim(strval($object));
    }

    public static function isHttpUrl($url)
    {
        return (strncasecmp($url, "http", 4) == 0) ? true : false;
    }

    public static function isRtmpUrl($url)
    {
        return (preg_match('/^rtm(p|pe|pt|pte|ps|pts|fp):\/\//i', $url)) ? true : false;
    }

    public static function JoinUrl($firstUrl, $secondUrl)
    {
        if ($firstUrl and $secondUrl) {
            if (substr($firstUrl, -1) == '/')
                $firstUrl = substr($firstUrl, 0, -1);
            if (substr($secondUrl, 0, 1) == '/')
                $secondUrl = substr($secondUrl, 1);
            return $firstUrl . '/' . $secondUrl;
        } else if ($firstUrl)
            return $firstUrl;
        else
            return $secondUrl;
    }

    public static function KeyName(array $a, $pos)
    {
        $temp = array_slice($a, $pos, 1, true);
        return key($temp);
    }

    public static function LogDebug($msg, $display = true)
    {
        if (self::$showHeader) {
            self::ShowHeader();
            self::$showHeader = false;
        }
        if ($display and self::$debug)
            fwrite(STDERR, $msg . "\n");
    }

    public static function LogError($msg, $code = 1)
    {
        self::LogInfo("\nERROR: " . $msg);
        exit($code);
    }

    public static function LogInfo($msg, $progress = false)
    {
        if (self::$showHeader) {
            self::ShowHeader();
            self::$showHeader = false;
        }
        if (!self::$quiet)
            self::PrintLine($msg, $progress);
    }

    public static function NormalizePath($path)
    {
        $inSegs = preg_split('/(?<!\/)\/(?!\/)/u', $path);
        $outSegs = array();

        foreach ($inSegs as $seg) {
            if ($seg == '' or $seg == '.')
                continue;
            if ($seg == '..')
                array_pop($outSegs);
            else
                array_push($outSegs, $seg);
        }
        $outPath = implode('/', $outSegs);

        if (substr($path, 0, 1) == '/')
            $outPath = '/' . $outPath;
        if (substr($path, -1) == '/')
            $outPath .= '/';
        return $outPath;
    }

    public static function PrintLine($msg, $progress = false)
    {
        if ($msg) {
            if ($progress) {
                printf("\r%-79s\r", "");
                printf("%s", $msg);
            } else {
                printf("%s\n", $msg);
            }
        } else
            printf("\n");
    }

    public static function RemoveExtension($outFile)
    {
        preg_match("/\.\w{1,4}$/i", $outFile, $extension);
        if (isset($extension[0])) {
            $extension = $extension[0];
            $outFile = substr($outFile, 0, -strlen($extension));
            return $outFile;
        }
        return $outFile;
    }

    public static function RenameFragments($baseFilename, $fragNum, $fileExt)
    {
        $files = array();
        $retries = 0;

        while (true) {
            if ($retries >= 50)
                break;
            $file = $baseFilename . ++$fragNum;
            if (file_exists($file)) {
                $files[] = $file;
                $retries = 0;
            } else if (file_exists($file . $fileExt)) {
                $files[] = $file . $fileExt;
                $retries = 0;
            } else
                $retries++;
        }

        $fragCount = count($files);
        natsort($files);
        for ($i = 0; $i < $fragCount; $i++)
            rename($files[$i], $baseFilename . ($i + 1));
    }

    public static function ShowHeader()
    {
        printf("KSV Adobe HDS Downloader\n\n");
    }

    public static function WriteFlvFile($outFile, $audio = true, $video = true)
    {
        $flvHeader = pack("H*", "464c5601050000000900000000");
        $flvHeaderLen = strlen($flvHeader);

        // Set proper Audio/Video marker
        self::WriteByte($flvHeader, 4, $audio << 2 | $video);

        if (is_resource($outFile))
            $flv = $outFile;
        else
            $flv = fopen($outFile, "w+b");
        if (!$flv)
            self::LogError("Failed to open " . $outFile);
        fwrite($flv, $flvHeader, $flvHeaderLen);
        return $flv;
    }

    public static function WriteMetadata($f4f, $flv = false)
    {
        if (isset($f4f->media) and $f4f->media['metadata']) {
            $metadataSize = strlen($f4f->media['metadata']);
            self::WriteByte($metadata, 0, SCRIPT_DATA);
            self::WriteInt24($metadata, 1, $metadataSize);
            self::WriteInt24($metadata, 4, 0);
            self::WriteInt32($metadata, 7, 0);
            $metadata = implode("", $metadata) . $f4f->media['metadata'];
            self::WriteByte($metadata, $f4f->tagHeaderLen + $metadataSize - 1, 0x09);
            self::WriteInt32($metadata, $f4f->tagHeaderLen + $metadataSize, $f4f->tagHeaderLen + $metadataSize);
            if (is_resource($flv)) {
                fwrite($flv, $metadata, $f4f->tagHeaderLen + $metadataSize + $f4f->prevTagSize);
                return true;
            } else
                return $metadata;
        }
        return false;
    }

    public static function in_array_field($needle, $needle_field, $haystack, $strict = false)
    {
        if ($strict) {
            foreach ($haystack as $item)
                if (isset($item[$needle_field]) and $item[$needle_field] === $needle)
                    return true;
        } else {
            foreach ($haystack as $item)
                if (isset($item[$needle_field]) and $item[$needle_field] == $needle)
                    return true;
        }
        return false;
    }

    public static function value_in_array_field($needle, $needle_field, $value_field, $haystack, $strict = false)
    {
        if ($strict) {
            foreach ($haystack as $item)
                if (isset($item[$needle_field]) and $item[$needle_field] === $needle)
                    return $item[$value_field];
        } else {
            foreach ($haystack as $item)
                if (isset($item[$needle_field]) and $item[$needle_field] == $needle)
                    return $item[$value_field];
        }
        return false;
    }
}