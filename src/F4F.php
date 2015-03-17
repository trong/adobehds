<?php
namespace AdobeHDS;

class F4F
{
    var $audio, $auth, $baseFilename, $baseTS, $bootstrapUrl, $baseUrl, $debug, $duration, $fileCount, $filesize, $fixWindow;
    var $format, $live, $media, $metadata, $outDir, $outFile, $parallel, $play, $processed, $quality, $rename, $video;
    var $prevTagSize, $tagHeaderLen;
    var $segTable, $fragTable, $segNum, $fragNum, $frags, $fragCount, $lastFrag, $fragUrl, $discontinuity;
    var $prevAudioTS, $prevVideoTS, $pAudioTagLen, $pVideoTagLen, $pAudioTagPos, $pVideoTagPos;
    var $prevAVC_Header, $prevAAC_Header, $AVC_HeaderWritten, $AAC_HeaderWritten;

    function __construct()
    {
        $this->auth = "";
        $this->baseFilename = "";
        $this->bootstrapUrl = "";
        $this->debug = false;
        $this->duration = 0;
        $this->fileCount = 1;
        $this->fixWindow = 1000;
        $this->format = "";
        $this->live = false;
        $this->metadata = true;
        $this->outDir = "";
        $this->outFile = "";
        $this->parallel = 8;
        $this->play = false;
        $this->processed = false;
        $this->quality = "high";
        $this->rename = false;
        $this->segTable = array();
        $this->fragTable = array();
        $this->segStart = false;
        $this->fragStart = false;
        $this->frags = array();
        $this->fragCount = 0;
        $this->lastFrag = 0;
        $this->discontinuity = "";
        $this->InitDecoder();
    }

    function InitDecoder()
    {
        $this->audio = false;
        $this->filesize = 0;
        $this->video = false;
        $this->prevTagSize = 4;
        $this->tagHeaderLen = 11;
        $this->baseTS = INVALID_TIMESTAMP;
        $this->negTS = INVALID_TIMESTAMP;
        $this->prevAudioTS = INVALID_TIMESTAMP;
        $this->prevVideoTS = INVALID_TIMESTAMP;
        $this->pAudioTagLen = 0;
        $this->pVideoTagLen = 0;
        $this->pAudioTagPos = 0;
        $this->pVideoTagPos = 0;
        $this->prevAVC_Header = false;
        $this->prevAAC_Header = false;
        $this->AVC_HeaderWritten = false;
        $this->AAC_HeaderWritten = false;
    }

    function GetManifest($cc, $manifest)
    {
        $status = $cc->get($manifest);
        if ($status == 403)
            Utils::LogError("Access Denied! Unable to download the manifest.");
        else if ($status != 200)
            Utils::LogError("Unable to download the manifest");
        $xml = simplexml_load_string(trim($cc->response));
        if (!$xml)
            Utils::LogError("Failed to load xml");
        $namespace = $xml->getDocNamespaces();
        $namespace = $namespace[''];
        $xml->registerXPathNamespace("ns", $namespace);
        return $xml;
    }

    function ParseManifest($cc, $parentManifest)
    {
        Utils::LogInfo("Processing manifest info....");
        $xml = $this->GetManifest($cc, $parentManifest);

        // Extract baseUrl from manifest url
        $baseUrl = $xml->xpath("/ns:manifest/ns:baseURL");
        if (isset($baseUrl[0]))
            $baseUrl = Utils::GetString($baseUrl[0]);
        else {
            $baseUrl = $parentManifest;
            if (strpos($baseUrl, '?') !== false)
                $baseUrl = substr($baseUrl, 0, strpos($baseUrl, '?'));
            $baseUrl = substr($baseUrl, 0, strrpos($baseUrl, '/'));
        }

        $url = $xml->xpath("/ns:manifest/ns:media[@*]");
        if (isset($url[0]['href'])) {
            $count = 1;
            foreach ($url as $childManifest) {
                if (isset($childManifest['bitrate']))
                    $bitrate = floor(Utils::GetString($childManifest['bitrate']));
                else
                    $bitrate = $count++;
                $entry =& $childManifests[$bitrate];
                $entry['bitrate'] = $bitrate;
                $entry['url'] = Utils::AbsoluteUrl($baseUrl, Utils::GetString($childManifest['href']));
                $entry['xml'] = $this->GetManifest($cc, $entry['url']);
            }
            unset($entry, $childManifest);
        } else {
            $childManifests[0]['bitrate'] = 0;
            $childManifests[0]['url'] = $parentManifest;
            $childManifests[0]['xml'] = $xml;
        }

        $count = 1;
        foreach ($childManifests as $childManifest) {
            $xml = $childManifest['xml'];

            // Extract baseUrl from manifest url
            $baseUrl = $xml->xpath("/ns:manifest/ns:baseURL");
            if (isset($baseUrl[0]))
                $baseUrl = Utils::GetString($baseUrl[0]);
            else {
                $baseUrl = $childManifest['url'];
                if (strpos($baseUrl, '?') !== false)
                    $baseUrl = substr($baseUrl, 0, strpos($baseUrl, '?'));
                $baseUrl = substr($baseUrl, 0, strrpos($baseUrl, '/'));
            }

            $streams = $xml->xpath("/ns:manifest/ns:media");
            foreach ($streams as $stream) {
                $array = array();
                foreach ($stream->attributes() as $k => $v)
                    $array[strtolower($k)] = Utils::GetString($v);
                $array['metadata'] = Utils::GetString($stream->{'metadata'});
                $stream = $array;

                if (isset($stream['bitrate']))
                    $bitrate = floor($stream['bitrate']);
                else if ($childManifest['bitrate'] > 0)
                    $bitrate = $childManifest['bitrate'];
                else
                    $bitrate = $count++;
                while (isset($this->media[$bitrate]))
                    $bitrate++;
                $streamId = isset($stream[strtolower('streamId')]) ? $stream[strtolower('streamId')] : "";
                $mediaEntry =& $this->media[$bitrate];

                $mediaEntry['baseUrl'] = $baseUrl;
                $mediaEntry['url'] = $stream['url'];
                if (Utils::isRtmpUrl($mediaEntry['baseUrl']) or Utils::isRtmpUrl($mediaEntry['url']))
                    Utils::LogError("Provided manifest is not a valid HDS manifest");

                // Use embedded auth information when available
                $idx = strpos($mediaEntry['url'], '?');
                if ($idx !== false) {
                    $mediaEntry['queryString'] = substr($mediaEntry['url'], $idx);
                    $mediaEntry['url'] = substr($mediaEntry['url'], 0, $idx);
                    if (strlen($this->auth) != 0 and strcmp($this->auth, $mediaEntry['queryString']) != 0)
                       Utils::LogDebug("Manifest overrides 'auth': " . $mediaEntry['queryString']);
                } else
                    $mediaEntry['queryString'] = $this->auth;

                if (isset($stream[strtolower('bootstrapInfoId')]))
                    $bootstrap = $xml->xpath("/ns:manifest/ns:bootstrapInfo[@id='" . $stream[strtolower('bootstrapInfoId')] . "']");
                else
                    $bootstrap = $xml->xpath("/ns:manifest/ns:bootstrapInfo");
                if (isset($bootstrap[0]['url'])) {
                    $mediaEntry['bootstrapUrl'] = Utils::AbsoluteUrl($mediaEntry['baseUrl'], Utils::GetString($bootstrap[0]['url']));
                    if (strpos($mediaEntry['bootstrapUrl'], '?') === false)
                        $mediaEntry['bootstrapUrl'] .= $this->auth;
                } else
                    $mediaEntry['bootstrap'] = base64_decode(Utils::GetString($bootstrap[0]));
                if (isset($stream['metadata']))
                    $mediaEntry['metadata'] = base64_decode($stream['metadata']);
                else
                    $mediaEntry['metadata'] = "";
            }
            unset($mediaEntry, $childManifest);
        }

        // Available qualities
        $bitrates = array();
        if (!count($this->media))
            Utils::LogError("No media entry found");
        krsort($this->media, SORT_NUMERIC);
       Utils::LogDebug("Manifest Entries:\n");
       Utils::LogDebug(sprintf(" %-8s%s", "Bitrate", "URL"));
        for ($i = 0; $i < count($this->media); $i++) {
            $key = Utils::KeyName($this->media, $i);
            $bitrates[] = $key;
           Utils::LogDebug(sprintf(" %-8d%s", $key, $this->media[$key]['url']));
        }
       Utils::LogDebug("");
        Utils::LogInfo("Quality Selection:\n Available: " . implode(' ', $bitrates));

        // Quality selection
        if (is_numeric($this->quality) and isset($this->media[$this->quality])) {
            $key = $this->quality;
            $this->media = $this->media[$key];
        } else {
            $this->quality = strtolower($this->quality);
            switch ($this->quality) {
                case "low":
                    $this->quality = 2;
                    break;
                case "medium":
                    $this->quality = 1;
                    break;
                default:
                    $this->quality = 0;
            }
            while ($this->quality >= 0) {
                $key = Utils::KeyName($this->media, $this->quality);
                if ($key !== NULL) {
                    $this->media = $this->media[$key];
                    break;
                } else
                    $this->quality -= 1;
            }
        }
        Utils::LogInfo(" Selected : " . $key);

        // Parse initial bootstrap info
        $this->baseUrl = $this->media['baseUrl'];
        if (isset($this->media['bootstrapUrl'])) {
            $this->bootstrapUrl = $this->media['bootstrapUrl'];
            $this->UpdateBootstrapInfo($cc, $this->bootstrapUrl);
        } else {
            $bootstrapInfo = $this->media['bootstrap'];
            Utils::ReadBoxHeader($bootstrapInfo, $pos, $boxType, $boxSize);
            if ($boxType == "abst")
                $this->ParseBootstrapBox($bootstrapInfo, $pos);
            else
                Utils::LogError("Failed to parse bootstrap info");
        }
    }

    function UpdateBootstrapInfo($cc, $bootstrapUrl)
    {
        $fragNum = $this->fragCount;
        $retries = 0;

        // Backup original headers and add no-cache directive for fresh bootstrap info
        $headers = $cc->headers;
        $cc->headers[] = "Cache-Control: no-cache";
        $cc->headers[] = "Pragma: no-cache";

        while (($fragNum == $this->fragCount) and ($retries < 30)) {
            $bootstrapPos = 0;
           Utils::LogDebug("Updating bootstrap info, Available fragments: " . $this->fragCount);
            $status = $cc->get($bootstrapUrl);
            if ($status != 200)
                Utils::LogError("Failed to refresh bootstrap info, Status: " . $status);
            $bootstrapInfo = $cc->response;
            Utils::ReadBoxHeader($bootstrapInfo, $bootstrapPos, $boxType, $boxSize);
            if ($boxType == "abst")
                $this->ParseBootstrapBox($bootstrapInfo, $bootstrapPos);
            else
                Utils::LogError("Failed to parse bootstrap info");
           Utils::LogDebug("Update complete, Available fragments: " . $this->fragCount);
            if ($fragNum == $this->fragCount) {
               Utils::LogDebug("Updating bootstrap info, Retries: " . ++$retries);
                usleep(4000000);
            }
        }

        // Restore original headers
        $cc->headers = $headers;
    }

    function ParseBootstrapBox($bootstrapInfo, $pos)
    {
        $version = Utils::ReadByte($bootstrapInfo, $pos);
        $flags = Utils::ReadInt24($bootstrapInfo, $pos + 1);
        $bootstrapVersion = Utils::ReadInt32($bootstrapInfo, $pos + 4);
        $byte = Utils::ReadByte($bootstrapInfo, $pos + 8);
        $profile = ($byte & 0xC0) >> 6;
        if (($byte & 0x20) >> 5) {
            $this->live = true;
            $this->metadata = false;
        }
        $update = ($byte & 0x10) >> 4;
        if (!$update) {
            $this->segTable = array();
            $this->fragTable = array();
        }
        $timescale = Utils::ReadInt32($bootstrapInfo, $pos + 9);
        $currentMediaTime = Utils::ReadInt64($bootstrapInfo, $pos + 13);
        $smpteTimeCodeOffset = Utils::ReadInt64($bootstrapInfo, $pos + 21);
        $pos += 29;
        $movieIdentifier = Utils::ReadString($bootstrapInfo, $pos);
        $serverEntryCount = Utils::ReadByte($bootstrapInfo, $pos++);
        for ($i = 0; $i < $serverEntryCount; $i++)
            $serverEntryTable[$i] = Utils::ReadString($bootstrapInfo, $pos);
        $qualityEntryCount = Utils::ReadByte($bootstrapInfo, $pos++);
        for ($i = 0; $i < $qualityEntryCount; $i++)
            $qualityEntryTable[$i] = Utils::ReadString($bootstrapInfo, $pos);
        $drmData = Utils::ReadString($bootstrapInfo, $pos);
        $metadata = Utils::ReadString($bootstrapInfo, $pos);
        $segRunTableCount = Utils::ReadByte($bootstrapInfo, $pos++);
       Utils::LogDebug(sprintf("%s:", "Segment Tables"));
        for ($i = 0; $i < $segRunTableCount; $i++) {
           Utils::LogDebug(sprintf("\nTable %d:", $i + 1));
            Utils::ReadBoxHeader($bootstrapInfo, $pos, $boxType, $boxSize);
            if ($boxType == "asrt")
                $segTable[$i] = $this->ParseAsrtBox($bootstrapInfo, $pos);
            $pos += $boxSize;
        }
        $fragRunTableCount = Utils::ReadByte($bootstrapInfo, $pos++);
       Utils::LogDebug(sprintf("%s:", "Fragment Tables"));
        for ($i = 0; $i < $fragRunTableCount; $i++) {
           Utils::LogDebug(sprintf("\nTable %d:", $i + 1));
            Utils::ReadBoxHeader($bootstrapInfo, $pos, $boxType, $boxSize);
            if ($boxType == "afrt")
                $fragTable[$i] = $this->ParseAfrtBox($bootstrapInfo, $pos);
            $pos += $boxSize;
        }
        $this->segTable = array_replace($this->segTable, $segTable[0]);
        $this->fragTable = array_replace($this->fragTable, $fragTable[0]);
        $this->ParseSegAndFragTable();
    }

    function ParseAsrtBox($asrt, $pos)
    {
        $segTable = array();
        $version = Utils::ReadByte($asrt, $pos);
        $flags = Utils::ReadInt24($asrt, $pos + 1);
        $qualityEntryCount = Utils::ReadByte($asrt, $pos + 4);
        $pos += 5;
        for ($i = 0; $i < $qualityEntryCount; $i++)
            $qualitySegmentUrlModifiers[$i] = Utils::ReadString($asrt, $pos);
        $segCount = Utils::ReadInt32($asrt, $pos);
        $pos += 4;
       Utils::LogDebug(sprintf(" %-8s%-10s", "Number", "Fragments"));
        for ($i = 0; $i < $segCount; $i++) {
            $firstSegment = Utils::ReadInt32($asrt, $pos);
            $segEntry =& $segTable[$firstSegment];
            $segEntry['firstSegment'] = $firstSegment;
            $segEntry['fragmentsPerSegment'] = Utils::ReadInt32($asrt, $pos + 4);
            if ($segEntry['fragmentsPerSegment'] & 0x80000000)
                $segEntry['fragmentsPerSegment'] = 0;
            $pos += 8;
        }
        unset($segEntry);
        foreach ($segTable as $segEntry)
           Utils::LogDebug(sprintf(" %-8s%-10s", $segEntry['firstSegment'], $segEntry['fragmentsPerSegment']));
       Utils::LogDebug("");
        return $segTable;
    }

    function ParseAfrtBox($afrt, $pos)
    {
        $fragTable = array();
        $version = Utils::ReadByte($afrt, $pos);
        $flags = Utils::ReadInt24($afrt, $pos + 1);
        $timescale = Utils::ReadInt32($afrt, $pos + 4);
        $qualityEntryCount = Utils::ReadByte($afrt, $pos + 8);
        $pos += 9;
        for ($i = 0; $i < $qualityEntryCount; $i++)
            $qualitySegmentUrlModifiers[$i] = Utils::ReadString($afrt, $pos);
        $fragEntries = Utils::ReadInt32($afrt, $pos);
        $pos += 4;
       Utils::LogDebug(sprintf(" %-12s%-16s%-16s%-16s", "Number", "Timestamp", "Duration", "Discontinuity"));
        for ($i = 0; $i < $fragEntries; $i++) {
            $firstFragment = Utils::ReadInt32($afrt, $pos);
            $fragEntry =& $fragTable[$firstFragment];
            $fragEntry['firstFragment'] = $firstFragment;
            $fragEntry['firstFragmentTimestamp'] = Utils::ReadInt64($afrt, $pos + 4);
            $fragEntry['fragmentDuration'] = Utils::ReadInt32($afrt, $pos + 12);
            $fragEntry['discontinuityIndicator'] = "";
            $pos += 16;
            if ($fragEntry['fragmentDuration'] == 0)
                $fragEntry['discontinuityIndicator'] = Utils::ReadByte($afrt, $pos++);
        }
        unset($fragEntry);
        foreach ($fragTable as $fragEntry)
            Utils::LogDebug(sprintf(" %-12s%-16s%-16s%-16s", $fragEntry['firstFragment'], $fragEntry['firstFragmentTimestamp'], $fragEntry['fragmentDuration'], $fragEntry['discontinuityIndicator']));
        Utils::LogDebug("");
        return $fragTable;
    }

    function ParseSegAndFragTable()
    {
        $firstSegment = reset($this->segTable);
        $lastSegment = end($this->segTable);
        $firstFragment = reset($this->fragTable);
        $lastFragment = end($this->fragTable);

        // Check if live stream is still live
        if (($lastFragment['fragmentDuration'] == 0) and ($lastFragment['discontinuityIndicator'] == 0)) {
            $this->live = false;
            array_pop($this->fragTable);
            $lastFragment = end($this->fragTable);
        }

        // Count total fragments by adding all entries in compactly coded segment table
        $invalidFragCount = false;
        $prev = reset($this->segTable);
        $this->fragCount = $prev['fragmentsPerSegment'];
        while ($current = next($this->segTable)) {
            $this->fragCount += ($current['firstSegment'] - $prev['firstSegment'] - 1) * $prev['fragmentsPerSegment'];
            $this->fragCount += $current['fragmentsPerSegment'];
            $prev = $current;
        }
        if (!($this->fragCount & 0x80000000))
            $this->fragCount += $firstFragment['firstFragment'] - 1;
        if ($this->fragCount & 0x80000000) {
            $this->fragCount = 0;
            $invalidFragCount = true;
        }
        if ($this->fragCount < $lastFragment['firstFragment'])
            $this->fragCount = $lastFragment['firstFragment'];

        // Determine starting segment and fragment
        if ($this->segStart === false) {
            if ($this->live)
                $this->segStart = $lastSegment['firstSegment'];
            else
                $this->segStart = $firstSegment['firstSegment'];
            if ($this->segStart < 1)
                $this->segStart = 1;
        }
        if ($this->fragStart === false) {
            if ($this->live and !$invalidFragCount)
                $this->fragStart = $this->fragCount - 2;
            else
                $this->fragStart = $firstFragment['firstFragment'] - 1;
            if ($this->fragStart < 0)
                $this->fragStart = 0;
        }
    }

    function GetSegmentFromFragment($fragNum)
    {
        $firstSegment = reset($this->segTable);
        $lastSegment = end($this->segTable);
        $firstFragment = reset($this->fragTable);
        $lastFragment = end($this->fragTable);

        if (count($this->segTable) == 1)
            return $firstSegment['firstSegment'];
        else {
            $prev = $firstSegment['firstSegment'];
            $start = $firstFragment['firstFragment'];
            for ($i = $firstSegment['firstSegment']; $i <= $lastSegment['firstSegment']; $i++) {
                if (isset($this->segTable[$i]))
                    $seg = $this->segTable[$i];
                else
                    $seg = $prev;
                $end = $start + $seg['fragmentsPerSegment'];
                if (($fragNum >= $start) and ($fragNum < $end))
                    return $i;
                $prev = $seg;
                $start = $end;
            }
        }
        return $lastSegment['firstSegment'];
    }

    function DownloadFragments($cc, $manifest, $opt = array())
    {
        $start = 0;
        extract($opt, EXTR_IF_EXISTS);

        $this->ParseManifest($cc, $manifest);
        $segNum = $this->segStart;
        $fragNum = $this->fragStart;
        if ($start) {
            $segNum = $this->GetSegmentFromFragment($start);
            $fragNum = $start - 1;
            $this->segStart = $segNum;
            $this->fragStart = $fragNum;
        }
        $this->lastFrag = $fragNum;
        $opt['cc'] = $cc;
        $opt['duration'] = 0;
        $firstFragment = reset($this->fragTable);
        Utils::LogInfo(sprintf("Fragments Total: %s, First: %s, Start: %s, Parallel: %s", $this->fragCount, $firstFragment['firstFragment'], $fragNum + 1, $this->parallel));

        // Extract baseFilename
        $this->baseFilename = $this->media['url'];
        if (substr($this->baseFilename, -1) == '/')
            $this->baseFilename = substr($this->baseFilename, 0, -1);
        $this->baseFilename = Utils::RemoveExtension($this->baseFilename);
        $lastSlash = strrpos($this->baseFilename, '/');
        if ($lastSlash !== false)
            $this->baseFilename = substr($this->baseFilename, $lastSlash + 1);
        if (strpos($manifest, '?'))
            $this->baseFilename = md5(substr($manifest, 0, strpos($manifest, '?'))) . '_' . $this->baseFilename;
        else
            $this->baseFilename = md5($manifest) . '_' . $this->baseFilename;
        $this->baseFilename .= "Seg" . $segNum . "-Frag";

        if ($fragNum >= $this->fragCount)
            Utils::LogError("No fragment available for downloading");

        $this->fragUrl = Utils::AbsoluteUrl($this->baseUrl, $this->media['url']);
        Utils::LogDebug("Base Fragment Url:\n" . $this->fragUrl . "\n");
        Utils::LogDebug("Downloading Fragments:\n");

        while (($fragNum < $this->fragCount) or $cc->active) {
            while ((count($cc->ch) < $this->parallel) and ($fragNum < $this->fragCount)) {
                $frag = array();
                $fragNum = $fragNum + 1;
                $frag['id'] = $fragNum;
                Utils::LogInfo("Downloading $fragNum/$this->fragCount fragments", true);
                if (Utils::in_array_field($fragNum, "firstFragment", $this->fragTable, true))
                    $this->discontinuity = Utils::value_in_array_field($fragNum, "firstFragment", "discontinuityIndicator", $this->fragTable, true);
                else {
                    $closest = reset($this->fragTable);
                    $closest = $closest['firstFragment'];
                    while ($current = next($this->fragTable)) {
                        if ($current['firstFragment'] < $fragNum)
                            $closest = $current['firstFragment'];
                        else
                            break;
                    }
                    $this->discontinuity = Utils::value_in_array_field($closest, "firstFragment", "discontinuityIndicator", $this->fragTable, true);
                }
                if ($this->discontinuity !== "") {
                    Utils::LogDebug("Skipping fragment $fragNum due to discontinuity, Type: " . $this->discontinuity);
                    $frag['response'] = false;
                    $this->rename = true;
                } else if (file_exists($this->baseFilename . $fragNum)) {
                    Utils::LogDebug("Fragment $fragNum is already downloaded");
                    $frag['response'] = file_get_contents($this->baseFilename . $fragNum);
                }
                if (isset($frag['response'])) {
                    if ($this->WriteFragment($frag, $opt) === STOP_PROCESSING)
                        break 2;
                    else
                        continue;
                }

                Utils::LogDebug("Adding fragment $fragNum to download queue");
                $segNum = $this->GetSegmentFromFragment($fragNum);
                $cc->addDownload($this->fragUrl . "Seg" . $segNum . "-Frag" . $fragNum . $this->media['queryString'], $fragNum);
            }

            $downloads = $cc->checkDownloads();
            if ($downloads !== false) {
                for ($i = 0; $i < count($downloads); $i++) {
                    $frag = array();
                    $download = $downloads[$i];
                    $frag['id'] = $download['id'];
                    if ($download['status'] == 200) {
                        if ($this->VerifyFragment($download['response'])) {
                            Utils::LogDebug("Fragment " . $this->baseFilename . $download['id'] . " successfully downloaded");
                            if (!($this->live or $this->play))
                                file_put_contents($this->baseFilename . $download['id'], $download['response']);
                            $frag['response'] = $download['response'];
                        } else {
                            Utils::LogDebug("Fragment " . $download['id'] . " failed to verify");
                            Utils::LogDebug("Adding fragment " . $download['id'] . " to download queue");
                            $cc->addDownload($download['url'], $download['id']);
                        }
                    } else if ($download['status'] === false) {
                        Utils::LogDebug("Fragment " . $download['id'] . " failed to download");
                        Utils::LogDebug("Adding fragment " . $download['id'] . " to download queue");
                        $cc->addDownload($download['url'], $download['id']);
                    } else if ($download['status'] == 403)
                        Utils::LogError("Access Denied! Unable to download fragments.");
                    else if ($download['status'] == 503) {
                        Utils::LogDebug("Fragment " . $download['id'] . " seems temporary unavailable");
                        Utils::LogDebug("Adding fragment " . $download['id'] . " to download queue");
                        $cc->addDownload($download['url'], $download['id']);
                    } else {
                        Utils::LogDebug("Fragment " . $download['id'] . " doesn't exist, Status: " . $download['status']);
                        $frag['response'] = false;
                        $this->rename = true;

                        /* Resync with latest available fragment when we are left behind due to slow *
                         * connection and short live window on streaming server. make sure to reset  *
                         * the last written fragment.                                                */
                        if ($this->live and ($fragNum >= $this->fragCount) and ($i + 1 == count($downloads)) and !$cc->active) {
                            Utils::LogDebug("Trying to resync with latest available fragment");
                            if ($this->WriteFragment($frag, $opt) === STOP_PROCESSING)
                                break 2;
                            unset($frag['response']);
                            $this->UpdateBootstrapInfo($cc, $this->bootstrapUrl);
                            $fragNum = $this->fragCount - 1;
                            $this->lastFrag = $fragNum;
                        }
                    }
                    if (isset($frag['response']))
                        if ($this->WriteFragment($frag, $opt) === STOP_PROCESSING)
                            break 2;
                }
                unset($downloads, $download);
            }
            if ($this->live and ($fragNum >= $this->fragCount) and !$cc->active)
                $this->UpdateBootstrapInfo($cc, $this->bootstrapUrl);
        }

        Utils::LogInfo("");
        Utils::LogDebug("\nAll fragments downloaded successfully\n");
        $cc->stopDownloads();
        $this->processed = true;
    }

    function VerifyFragment(&$frag)
    {
        $fragPos = 0;
        $fragLen = strlen($frag);

        /* Some moronic servers add wrong boxSize in header causing fragment verification *
         * to fail so we have to fix the boxSize before processing the fragment.          */
        while ($fragPos < $fragLen) {
            Utils::ReadBoxHeader($frag, $fragPos, $boxType, $boxSize);
            if ($boxType == "mdat") {
                $len = strlen(substr($frag, $fragPos, $boxSize));
                if ($boxSize and ($len == $boxSize))
                    return true;
                else {
                    $boxSize = $fragLen - $fragPos;
                    Utils::WriteBoxSize($frag, $fragPos, $boxType, $boxSize);
                    return true;
                }
            }
            $fragPos += $boxSize;
        }
        return false;
    }

    function DecodeFragment($frag, $fragNum, $opt = array())
    {
        $debug = $this->debug;
        $flv = false;
        extract($opt, EXTR_IF_EXISTS);

        $flvData = "";
        $fragPos = 0;
        $packetTS = 0;
        $fragLen = strlen($frag);

        if (!$this->VerifyFragment($frag)) {
            Utils::LogInfo("Skipping fragment number $fragNum");
            return false;
        }

        while ($fragPos < $fragLen) {
            Utils::ReadBoxHeader($frag, $fragPos, $boxType, $boxSize);
            if ($boxType == "mdat") {
                $fragLen = $fragPos + $boxSize;
                break;
            }
            $fragPos += $boxSize;
        }

        Utils::LogDebug(sprintf("\nFragment %d:\n" . $this->format . "%-16s", $fragNum, "Type", "CurrentTS", "PreviousTS", "Size", "Position"), $debug);
        while ($fragPos < $fragLen) {
            $packetType = Utils::ReadByte($frag, $fragPos);
            $packetSize = Utils::ReadInt24($frag, $fragPos + 1);
            $packetTS = Utils::ReadInt24($frag, $fragPos + 4);
            $packetTS = $packetTS | (Utils::ReadByte($frag, $fragPos + 7) << 24);
            if ($packetTS & 0x80000000)
                $packetTS &= 0x7FFFFFFF;
            $totalTagLen = $this->tagHeaderLen + $packetSize + $this->prevTagSize;

            // Try to fix the odd timestamps and make them zero based
            $currentTS = $packetTS;
            $lastTS = $this->prevVideoTS >= $this->prevAudioTS ? $this->prevVideoTS : $this->prevAudioTS;
            $fixedTS = $lastTS + FRAMEFIX_STEP;
            if (($this->baseTS == INVALID_TIMESTAMP) and (($packetType == AUDIO) or ($packetType == VIDEO)))
                $this->baseTS = $packetTS;
            if (($this->baseTS > 1000) and ($packetTS >= $this->baseTS))
                $packetTS -= $this->baseTS;
            if ($lastTS != INVALID_TIMESTAMP) {
                $timeShift = $packetTS - $lastTS;
                if ($timeShift > $this->fixWindow) {
                    Utils::LogDebug("Timestamp gap detected: PacketTS=" . $packetTS . " LastTS=" . $lastTS . " Timeshift=" . $timeShift, $debug);
                    if ($this->baseTS < $packetTS)
                        $this->baseTS += $timeShift - FRAMEFIX_STEP;
                    else
                        $this->baseTS = $timeShift - FRAMEFIX_STEP;
                    $packetTS = $fixedTS;
                } else {
                    $lastTS = $packetType == VIDEO ? $this->prevVideoTS : $this->prevAudioTS;
                    if ($packetTS < ($lastTS - $this->fixWindow)) {
                        if (($this->negTS != INVALID_TIMESTAMP) and (($packetTS + $this->negTS) < ($lastTS - $this->fixWindow)))
                            $this->negTS = INVALID_TIMESTAMP;
                        if ($this->negTS == INVALID_TIMESTAMP) {
                            $this->negTS = $fixedTS - $packetTS;
                            Utils::LogDebug("Negative timestamp detected: PacketTS=" . $packetTS . " LastTS=" . $lastTS . " NegativeTS=" . $this->negTS, $debug);
                            $packetTS = $fixedTS;
                        } else {
                            if (($packetTS + $this->negTS) <= ($lastTS + $this->fixWindow))
                                $packetTS += $this->negTS;
                            else {
                                $this->negTS = $fixedTS - $packetTS;
                                Utils::LogDebug("Negative timestamp override: PacketTS=" . $packetTS . " LastTS=" . $lastTS . " NegativeTS=" . $this->negTS, $debug);
                                $packetTS = $fixedTS;
                            }
                        }
                    }
                }
            }
            if ($packetTS != $currentTS)
                Utils::WriteFlvTimestamp($frag, $fragPos, $packetTS);

            switch ($packetType) {
                case AUDIO:
                    if ($packetTS > $this->prevAudioTS - $this->fixWindow) {
                        $FrameInfo = Utils::ReadByte($frag, $fragPos + $this->tagHeaderLen);
                        $CodecID = ($FrameInfo & 0xF0) >> 4;
                        if ($CodecID == CODEC_ID_AAC) {
                            $AAC_PacketType = Utils::ReadByte($frag, $fragPos + $this->tagHeaderLen + 1);
                            if ($AAC_PacketType == AAC_SEQUENCE_HEADER) {
                                if ($this->AAC_HeaderWritten) {
                                    Utils::LogDebug(sprintf("%s\n" . $this->format, "Skipping AAC sequence header", "AUDIO", $packetTS, $this->prevAudioTS, $packetSize), $debug);
                                    break;
                                } else {
                                    Utils::LogDebug("Writing AAC sequence header", $debug);
                                    $this->AAC_HeaderWritten = true;
                                }
                            } else if (!$this->AAC_HeaderWritten) {
                                Utils::LogDebug(sprintf("%s\n" . $this->format, "Discarding audio packet received before AAC sequence header", "AUDIO", $packetTS, $this->prevAudioTS, $packetSize), $debug);
                                break;
                            }
                        }
                        if ($packetSize > 0) {
                            // Check for packets with non-monotonic audio timestamps and fix them
                            if (!(($CodecID == CODEC_ID_AAC) and (($AAC_PacketType == AAC_SEQUENCE_HEADER) or $this->prevAAC_Header)))
                                if (($this->prevAudioTS != INVALID_TIMESTAMP) and ($packetTS <= $this->prevAudioTS)) {
                                    Utils::LogDebug(sprintf("%s\n" . $this->format, "Fixing audio timestamp", "AUDIO", $packetTS, $this->prevAudioTS, $packetSize), $debug);
                                    $packetTS += (FRAMEFIX_STEP / 5) + ($this->prevAudioTS - $packetTS);
                                    Utils::WriteFlvTimestamp($frag, $fragPos, $packetTS);
                                }
                            if (is_resource($flv)) {
                                $this->pAudioTagPos = ftell($flv);
                                $status = fwrite($flv, substr($frag, $fragPos, $totalTagLen), $totalTagLen);
                                if (!$status)
                                    Utils::LogError("Failed to write flv data to file");
                                if ($debug)
                                    Utils::LogDebug(sprintf($this->format . "%-16s", "AUDIO", $packetTS, $this->prevAudioTS, $packetSize, $this->pAudioTagPos));
                            } else {
                                $flvData .= substr($frag, $fragPos, $totalTagLen);
                                if ($debug)
                                    Utils::LogDebug(sprintf($this->format, "AUDIO", $packetTS, $this->prevAudioTS, $packetSize));
                            }
                            if (($CodecID == CODEC_ID_AAC) and ($AAC_PacketType == AAC_SEQUENCE_HEADER))
                                $this->prevAAC_Header = true;
                            else
                                $this->prevAAC_Header = false;
                            $this->prevAudioTS = $packetTS;
                            $this->pAudioTagLen = $totalTagLen;
                        } else
                            Utils::LogDebug(sprintf("%s\n" . $this->format, "Skipping small sized audio packet", "AUDIO", $packetTS, $this->prevAudioTS, $packetSize), $debug);
                    } else
                        Utils::LogDebug(sprintf("%s\n" . $this->format, "Skipping audio packet in fragment $fragNum", "AUDIO", $packetTS, $this->prevAudioTS, $packetSize), $debug);
                    if (!$this->audio)
                        $this->audio = true;
                    break;
                case VIDEO:
                    if ($packetTS > $this->prevVideoTS - $this->fixWindow) {
                        $FrameInfo = Utils::ReadByte($frag, $fragPos + $this->tagHeaderLen);
                        $FrameType = ($FrameInfo & 0xF0) >> 4;
                        $CodecID = $FrameInfo & 0x0F;
                        if ($FrameType == FRAME_TYPE_INFO) {
                            Utils::LogDebug(sprintf("%s\n" . $this->format, "Skipping video info frame", "VIDEO", $packetTS, $this->prevVideoTS, $packetSize), $debug);
                            break;
                        }
                        if ($CodecID == CODEC_ID_AVC) {
                            $AVC_PacketType = Utils::ReadByte($frag, $fragPos + $this->tagHeaderLen + 1);
                            if ($AVC_PacketType == AVC_SEQUENCE_HEADER) {
                                if ($this->AVC_HeaderWritten) {
                                    Utils::LogDebug(sprintf("%s\n" . $this->format, "Skipping AVC sequence header", "VIDEO", $packetTS, $this->prevVideoTS, $packetSize), $debug);
                                    break;
                                } else {
                                    Utils::LogDebug("Writing AVC sequence header", $debug);
                                    $this->AVC_HeaderWritten = true;
                                }
                            } else if (!$this->AVC_HeaderWritten) {
                                Utils::LogDebug(sprintf("%s\n" . $this->format, "Discarding video packet received before AVC sequence header", "VIDEO", $packetTS, $this->prevVideoTS, $packetSize), $debug);
                                break;
                            }
                        }
                        if ($packetSize > 0) {
                            $pts = $packetTS;
                            if (($CodecID == CODEC_ID_AVC) and ($AVC_PacketType == AVC_NALU)) {
                                $cts = Utils::ReadInt24($frag, $fragPos + $this->tagHeaderLen + 2);
                                $cts = ($cts + 0xff800000) ^ 0xff800000;
                                $pts = $packetTS + $cts;
                                if ($cts != 0)
                                    Utils::LogDebug("DTS: $packetTS CTS: $cts PTS: $pts", $debug);
                            }

                            // Check for packets with non-monotonic video timestamps and fix them
                            if (!(($CodecID == CODEC_ID_AVC) and (($AVC_PacketType == AVC_SEQUENCE_HEADER) or ($AVC_PacketType == AVC_SEQUENCE_END) or $this->prevAVC_Header)))
                                if (($this->prevVideoTS != INVALID_TIMESTAMP) and ($packetTS <= $this->prevVideoTS)) {
                                    Utils::LogDebug(sprintf("%s\n" . $this->format, "Fixing video timestamp", "VIDEO", $packetTS, $this->prevVideoTS, $packetSize), $debug);
                                    $packetTS += (FRAMEFIX_STEP / 5) + ($this->prevVideoTS - $packetTS);
                                    Utils::WriteFlvTimestamp($frag, $fragPos, $packetTS);
                                }
                            if (is_resource($flv)) {
                                $this->pVideoTagPos = ftell($flv);
                                $status = fwrite($flv, substr($frag, $fragPos, $totalTagLen), $totalTagLen);
                                if (!$status)
                                    Utils::LogError("Failed to write flv data to file");
                                if ($debug)
                                    Utils::LogDebug(sprintf($this->format . "%-16s", "VIDEO", $packetTS, $this->prevVideoTS, $packetSize, $this->pVideoTagPos));
                            } else {
                                $flvData .= substr($frag, $fragPos, $totalTagLen);
                                if ($debug)
                                    Utils::LogDebug(sprintf($this->format, "VIDEO", $packetTS, $this->prevVideoTS, $packetSize));
                            }
                            if (($CodecID == CODEC_ID_AVC) and ($AVC_PacketType == AVC_SEQUENCE_HEADER))
                                $this->prevAVC_Header = true;
                            else
                                $this->prevAVC_Header = false;
                            $this->prevVideoTS = $packetTS;
                            $this->pVideoTagLen = $totalTagLen;
                        } else
                            Utils::LogDebug(sprintf("%s\n" . $this->format, "Skipping small sized video packet", "VIDEO", $packetTS, $this->prevVideoTS, $packetSize), $debug);
                    } else
                        Utils::LogDebug(sprintf("%s\n" . $this->format, "Skipping video packet in fragment $fragNum", "VIDEO", $packetTS, $this->prevVideoTS, $packetSize), $debug);
                    if (!$this->video)
                        $this->video = true;
                    break;
                case SCRIPT_DATA:
                    break;
                default:
                    if (($packetType == 10) or ($packetType == 11))
                        Utils::LogError("This stream is encrypted with Akamai DRM. Decryption of such streams isn't currently possible with this script.", 2);
                    else if (($packetType == 40) or ($packetType == 41))
                        Utils::LogError("This stream is encrypted with FlashAccess DRM. Decryption of such streams isn't currently possible with this script.", 2);
                    else {
                        Utils::LogInfo("Unknown packet type " . $packetType . " encountered! Unable to process fragment $fragNum");
                        break 2;
                    }
            }
            $fragPos += $totalTagLen;
        }
        $this->duration = round($packetTS / 1000, 0);
        if (is_resource($flv)) {
            $this->filesize = ftell($flv) / (1024 * 1024);
            return true;
        } else
            return $flvData;
    }

    function WriteFragment($download, &$opt)
    {
        $this->frags[$download['id']] = $download;

        $available = count($this->frags);
        for ($i = 0; $i < $available; $i++) {
            if (isset($this->frags[$this->lastFrag + 1])) {
                $frag = $this->frags[$this->lastFrag + 1];
                if ($frag['response'] !== false) {
                    Utils::LogDebug("Writing fragment " . $frag['id'] . " to flv file");
                    if (!isset($opt['file'])) {
                        $opt['debug'] = false;
                        if ($this->play)
                            $outFile = STDOUT;
                        else if ($this->outFile) {
                            if ($opt['filesize'])
                                $outFile = Utils::JoinUrl($this->outDir, $this->outFile . '-' . $this->fileCount++ . ".flv");
                            else
                                $outFile = Utils::JoinUrl($this->outDir, $this->outFile . ".flv");
                        } else {
                            if ($opt['filesize'])
                                $outFile = Utils::JoinUrl($this->outDir, $this->baseFilename . '-' . $this->fileCount++ . ".flv");
                            else
                                $outFile = Utils::JoinUrl($this->outDir, $this->baseFilename . ".flv");
                        }
                        $this->InitDecoder();
                        $this->DecodeFragment($frag['response'], $frag['id'], $opt);
                        $opt['file'] = Utils::WriteFlvFile($outFile, $this->audio, $this->video);
                        if ($this->metadata)
                            Utils::WriteMetadata($this, $opt['file']);

                        $opt['debug'] = $this->debug;
                        $this->InitDecoder();
                    }
                    $flvData = $this->DecodeFragment($frag['response'], $frag['id'], $opt);
                    if (strlen($flvData)) {
                        $status = fwrite($opt['file'], $flvData, strlen($flvData));
                        if (!$status)
                            Utils::LogError("Failed to write flv data");
                        if (!$this->play)
                            $this->filesize = ftell($opt['file']) / (1024 * 1024);
                    }
                    $this->lastFrag = $frag['id'];
                } else {
                    $this->lastFrag += 1;
                    Utils::LogDebug("Skipping failed fragment " . $this->lastFrag);
                }
                unset($this->frags[$this->lastFrag]);
            } else
                break;

            if ($opt['tDuration'] and (($opt['duration'] + $this->duration) >= $opt['tDuration'])) {
                Utils::LogInfo("");
                Utils::LogInfo(($opt['duration'] + $this->duration) . " seconds of content has been recorded successfully.", true);
                return STOP_PROCESSING;
            }
            if ($opt['filesize'] and ($this->filesize >= $opt['filesize'])) {
                $this->filesize = 0;
                $opt['duration'] += $this->duration;
                fclose($opt['file']);
                unset($opt['file']);
            }
        }

        if (!count($this->frags))
            unset($this->frags);
        return true;
    }
}