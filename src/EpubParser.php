<?php
namespace lywzx\epub;

class EpubParser {

    /**
     * @var string epub file path
     */
    private $filePath;

    /**
     * @var array
     */
    private $manifest = [];

    /**
     * Holds the (relative to $ebookDir) path to the OPF file
     * @var string Location + name of OPF file
     */
    private $opfFile;

    /**
     * Relative (to $ebookDir) OPF (ePub files) dir
     * @var string Files dir
     */
    private $opfDir;

    /**
     * @var \ZipArchive
     */
    private $zipArchive;

    /**
     * Holds all the found DC elements in the OPF file
     * @var array All found DC elements in the OPF file
     */
    private $dcElements;

    /**
     * Holds all the spin data
     * @var array Spine data
     */
    private $spine;

    /**
     * Holds the ToC data
     * @var array Array with ToC items
     */
    private $toc;

    /**
     * image extract static root
     * @var null|string
     */
    private $imageWebRoot = null;

    /**
     *
     * @var null|string
     */
    private $linkWebRoot = null;

    /**
     * @var string
     */
    private $directorySeparator = '/';

    /**
     * EpubParser constructor.
     * @param $filePath
     * @param null $imageWebRoot
     * @param null $linkWebRoot
     * @throws \Exception
     */
    public function __construct( $filePath, $imageWebRoot = null, $linkWebRoot = null )
    {
        $this->filePath = $filePath;
        $this->imageWebRoot = $imageWebRoot;
        $this->linkWebRoot  = $linkWebRoot;
        $this->zipArchive = new \ZipArchive();

        // check file
        $this->fileCheck();
    }


    /**
     * @return array
     */
    public function __sleep()
    {
        // TODO: add some attribute to sleep
        return [];
    }

    /**
     *
     */
    public function __wakeup()
    {
        // TODO: Implement __wakeup() method.
    }


    /**
     * check file is validated
     * @throws \Exception
     */
    private function fileCheck() {
        $this->open();

        $mimetype = $this->_getFileContentFromZipArchive('mimetype');
        if (strtolower($mimetype) !== 'application/epub+zip') {
            throw new \Exception('The epub file is not validated');
        }

        $this->close();
    }


    /**
     * parse epub file info
     * @throws \Exception
     */
    public function parse(){
        $this->open();

        $this->_getOPF();
        $this->_getDcData();
        $this->_getManifest();
        $this->_getSpine();
        $this->_getTOC();

        $this->close();
    }

    // Private functions

    /**
     * Get the path to the OPF file from the META-INF/container.xml file
     * @return string Relative path to the OPF file
     * @throws \Exception
     */
    private function _getOPF() {
        $file = "META-INF".$this->directorySeparator."container.xml";
        $buf = $this->_getFileContentFromZipArchive($file);
        
        $opfContents = @simplexml_load_string($buf);
        if ($opfContents === false) {
            throw new \Exception("Failed to parse container.xml");
        }
    
        if (!isset($opfContents->rootfiles) || !isset($opfContents->rootfiles->rootfile)) {
            throw new \Exception("Invalid container.xml structure - missing rootfiles");
        }
    
        $opfAttributes = $opfContents->rootfiles->rootfile->attributes();
        if ($opfAttributes === null) {
            throw new \Exception("No attributes found in rootfile");
        }
    
        $this->opfFile = (string) $opfAttributes->{'full-path'};
        
        $opfDirParts = explode($this->directorySeparator, $this->opfFile);
        if (count($opfDirParts) > 1) {
            unset($opfDirParts[count($opfDirParts)-1]);
            $this->opfDir = implode($this->directorySeparator, $opfDirParts);
        } else {
            $this->opfDir = '';
        }
    
        return $this->opfFile;
    }

    /**
     * Read the metadata DC details (title, author, etc.) from the OPF file
     * @throws \Exception
     */
    private function _getDcData() {
        $buf = $this->_getFileContentFromZipArchive($this->opfFile);
        $opfContents = @simplexml_load_string($buf);
        
        if ($opfContents === false) {
            $this->dcElements = [];
            return;
        }
    
        $dcChildren = isset($opfContents->metadata) ? $opfContents->metadata->children('dc', true) : null;
        
        if ($dcChildren === null) {
            $this->dcElements = [];
            return;
        }
    
        $dcArray = json_decode(json_encode($dcChildren, JSON_UNESCAPED_UNICODE), true);
        $this->dcElements = is_array($dcArray) ? $dcArray : [];
        
        // Clean up empty arrays
        $this->dcElements = array_map(function($item) {
            return is_array($item) && empty($item) ? '' : $item;
        }, $this->dcElements);
    }

    /**
     * Gets the manifest data from the OPF file
     * @throws \Exception
     */
    private function _getManifest() {
        $buf = $this->_getFileContentFromZipArchive($this->opfFile);
        $opfContents = @simplexml_load_string($buf);
        
        if ($opfContents === false || !isset($opfContents->manifest)) {
            $this->manifest = [];
            return;
        }
    
        foreach ($opfContents->manifest->item as $item) {
            $attr = $item->attributes();
            if ($attr === null) continue;
            
            $id = (string) ($attr->id ?? '');
            if ($id === '') continue;
            
            $href = (string) ($attr->href ?? '');
            $mediaType = (string) ($attr->{'media-type'} ?? '');
            
            if ($href !== '') {
                $this->manifest[$id] = [
                    'href' => Util::directoryConcat($this->opfDir, urldecode($href)),
                    'media-type' => $mediaType
                ];
            }
        }
    }

    /**
     * Get the spine data from the OPF file
     * @throws \Exception
     */
    private function _getSpine() {
        $buf = $this->_getFileContentFromZipArchive($this->opfFile);
        $opfContents = @simplexml_load_string($buf);
        
        if ($opfContents === false) {
            throw new \Exception("Failed to parse OPF file");
        }
    
        // Initialize empty spine array
        $this->spine = [];
    
        // Check if spine exists and has itemref elements
        if (!isset($opfContents->spine)) {
            error_log("Warning: No spine found in OPF file");
            return;
        }
    
        // Safely iterate through itemref elements
        if (isset($opfContents->spine->itemref)) {
            foreach ($opfContents->spine->itemref as $item) {
                $attr = $item->attributes();
                if ($attr !== null && isset($attr->idref)) {
                    $this->spine[] = (string)$attr->idref;
                }
            }
        }
    }


    /**
     * Build an array with the TOC
     * @throws \Exception
     */
    private function _getTOC() {
        $tocFile = $this->getManifest('ncx');
        if ($tocFile === false) {
            // Try alternative ways to find the TOC file
            $tocFiles = $this->getManifestByType('application/x-dtbncx+xml');
            if ($tocFiles === false || empty($tocFiles)) {
                $this->toc = [];
                return;
            }
            $tocFile = reset($tocFiles); // Get first TOC file found
        }
    
        try {
            $buf = $this->_getFileContentFromZipArchive($tocFile['href']);
            $tocContents = simplexml_load_string($buf);
            if ($tocContents === false) {
                throw new \Exception('Failed to parse TOC XML');
            }
    
            $callback = function($navPoints) use(& $callback) {
                $ret = [];
                foreach ($navPoints as $navPoint) {
                    $attributes = $navPoint->attributes();
                    $src = Util::directoryConcat($this->opfDir, (string) $navPoint->content->attributes());
                    $explodeUrl = strpos($src, "#") ? explode("#", $src) : [$src, null];
                    $current = [
                        'id' => (string) $attributes['id'],
                        'name' => (string) $navPoint->navLabel->text,
                        'file_name' => $explodeUrl[0],
                        'src'  => $src,
                        'page_id' => $explodeUrl[1]
                    ];
    
                    if (isset($navPoint->navPoint) && !empty($navPoint->navPoint)) {
                        $current['children'] = $callback($navPoint->navPoint);
                    }
                    $ret[] = $current;
                }
                return $ret;
            };
    
            $toc = $callback($tocContents->navMap->navPoint);
            $this->toc = $toc;
        } catch (\Exception $e) {
            error_log("Error parsing TOC: " . $e->getMessage());
            $this->toc = [];
        }
    }

    /**
     * read file from Archive file
     * @param $fileName
     * @return string
     * @throws \Exception
     */
    private function _getFileContentFromZipArchive($fileName) {
        $fp = $this->zipArchive->getStream($fileName);
        if (!$fp) {
            throw new \Exception("Error: can't get stream to epub file");
        }
        //$stat = $zip->statName($fileName);

        $buf = ""; //file buffer
        ob_start(); //to capture CRC error message
        while (!feof($fp)) {
            $buf .= fread($fp, 2048); //reading more than 2156 bytes seems to disable internal CRC32 verification (bug?)
        }
        $s = ob_get_contents();
        ob_end_clean();
        if(stripos($s, "CRC error") != FALSE){
            throw new \Exception('CRC error');
            /*echo 'CRC32 mismatch, current ';
            printf("%08X", crc32($buf)); //current CRC
            echo ', expected ';
            printf("%08X", $stat['crc']); //expected CRC*/
        }
        fclose($fp);
        return $buf;
    }


    /**
     * get ebook spine file id
     * @return array
     */
    public function getSpine() {
        return $this->spine;
    }

    /**
     * Get the specified manifest item
     * @param string $item The manifest ID
     * @return string|boolean|array String when manifest item exists, otherwise false
     */
    public function getManifest($item = null) {
        if ( is_null($item) ) {
            return $this->manifest;
        } else if(is_string($item) && key_exists($item, $this->manifest)) {
            return $this->manifest[$item];
        } else {
            return false;
        }
    }

    /**
     * Get the specified DC item
     * @param string $item The DC Item key
     * @return string|boolean|array String when DC item exists, otherwise false
     */
    public function getDcItem($item = null) {
        if ( is_null($item) ) {
            return $this->dcElements;
        } else if(is_string($item) && key_exists($item, $this->dcElements)) {
            return $this->dcElements[$item];
        } else {
            return false;
        }
    }

    /**
     * Get the specified manifest by type
     * @param string $pattern The manifest type
     * @return string|boolean|array String when manifest item exists, otherwise false
     */
    public function getManifestByType($pattern) {
        $isRegExp =  @preg_match($pattern, '') !== FALSE;
        $ret = array_filter($this->manifest, function($manifest) use($pattern, $isRegExp) {
            if ($isRegExp) {
                return preg_match($pattern, $manifest['media-type']);
            }
            return $manifest['media-type'] === $pattern;
        });

        return (count($ret) == 0) ? false : $ret;
    }

    /**
     * start open epub file
     * @throws \Exception
     */
    private function open() {
        $zip_status = $this->zipArchive->open($this->filePath);
        if ( $zip_status !== true ) {
            throw new \Exception("Failed opening ebook: ". @$this->zipArchive->getStatusString(), $zip_status );
        }
    }

    /**
     * close epub file
     */
    private function close() {
        $this->zipArchive->close();
    }

    /**
     * Retrieve the ToC
     * @return array Array with ToC Data
     */
    public function getTOC() {
        return $this->toc;
    }

    /**
     * get the path relative the file root
     * @param $href string
     * @return string
     */
    private function getFileRealPath($href){
        $opfDir = $this->opfDir;
        $href   = str_replace(array('/', '\\'), $this->directorySeparator, $href);

        $path = [rtrim($opfDir, $this->directorySeparator), ltrim($href, $this->directorySeparator)];

        return implode($this->directorySeparator, array_filter($path));
    }

    /**
     * Returns the OPF/Data dir
     * @return string The OPF/data dir
     */
    private function getOPFDir() {
        return $this->opfDir;
    }

    /**
     * get chapter html text
     * @param $chapterId string chapterId
     * @return string
     * @throws \Exception
     */
    public function getChapter($chapterId) {
        $result = $this->getChapterRaw($chapterId);
        $chapterHref = $this->getManifest($chapterId)['href'];

        // remove linebreaks (no multi line matches in JS regex!)
        $result = preg_replace("/\r?\n/", "\u0000", $result);

        // keep only <body> contents
        $match = [];
        preg_match('/<body[^>]*?>(.*)<\/body[^>]*?>/i', $result, $match);
        $result = trim($match[1]);

        // remove <script> blocks if any
        $result = preg_replace('/<script[^>]*?>(.*?)<\/script[^>]*?>/i', '', $result);

        // remove <style> blocks if any
        $result = preg_replace('/<style[^>]*?>(.*?)<\/style[^>]*?>/i', '', $result);

        // remove onEvent handlers
        $result = preg_replace_callback('/(\s)(on\w+)(\s*=\s*["\']?[^"\'\s>]*?["\'\s>])/', function($matches){
            return $matches[1]."skip-".$matches[2].$matches[3];
        }, $result);

        // replace images
        $result = preg_replace_callback('/(\s(?:xlink:href|src)\s*=\s*["\']?)([^"\'\s>]*?)(["\'\s>])/', function($matches) use($chapterHref){
            $img = Util::directoryConcat($chapterHref, urldecode($matches[2]), True);

            $element = null;
            foreach ($this->manifest as $key => $value) {
                $mainestUrl = $value['href'];
                if ($mainestUrl === $img) {
                    $element = $value;
                    break;
                }
            }
            if (!is_null($element)) {
                return $matches[1].$this->imageWebRoot.'/'.$img.$matches[3];
            }
            return '';
        }, $result);

        $result = preg_replace_callback('/(\shref\s*=\s*["\']?)([^"\'\s>]*?)(["\'\s>])/', function($matches) use($chapterHref){
            $linkparts = isset($matches[2]) ? explode("#", Util::directoryConcat($chapterHref, urldecode($matches[2]), true)): [];
            $link    = array_shift($linkparts) ?? '';
            $element   = null;

            if ($link) {
                foreach ($this->manifest as $key => $value) {
                    if(explode('#', $value['href'])[0] === $link) {
                        $element = $value;
                        break;
                    }
                }
            }

            if (count($linkparts)) {
                $link .= '#'.implode( '#',$linkparts);
            }

            // include only images from manifest
            if ($element) {
                return $matches[1].$this->linkWebRoot."/".$link.$matches[3];
            }
            return $matches[1].$matches[2].$matches[3];
        }, $result);

        return preg_replace("/\\\u0000/", "\n",  $result);
    }

    /**
     * get chapter html text
     * @param $chapterId string chapterId
     * @return string
     * @throws \Exception
     */
    public function getChapterRaw($chapterId) {
        if (isset($this->manifest[$chapterId])) {
            $chapter = $this->manifest[$chapterId];
            if (!($chapter['media-type'] == "application/xhtml+xml" || $chapter['media-type'] == "image/svg+xml")) {
                throw new \Exception('Invalid mime type for chapter');
            }
            $filePath = $chapter['href'];
            $this->open();
            $result = $this->_getFileContentFromZipArchive($filePath);
            $this->close();
            return $result;
        }
        throw new \Exception('File not found');
    }

    /**
     * @param $imageId
     * @return string
     * @throws \Exception
     */
    public function getImage($imageId) {
        if (isset($this->manifest[$imageId])) {
            $image = $this->manifest[$imageId];
            if (substr(trim(strtolower($image['media-type'] ?? "")),0, 6)  !=  "image/") {
                throw new \Exception("Invalid mime type for image");
            }
            $this->open();
            $result = $this->_getFileContentFromZipArchive($image['href']);
            $this->close();
            return $result;
        }
        throw new \Exception("file not found");
    }

    /**
     * @param $fileId string
     * @return string
     * @throws \Exception
     */
    public function getFile($fileId) {
        if (isset($this->manifest[$fileId])) {
            $file = $this->manifest[$fileId];
            $this->open();
            try {
                $result = $this->_getFileContentFromZipArchive( $file['href']);
                $this->close();
                return $result;
            } catch (\Exception $e) {

            }
        }
        throw new \Exception("file not found");
    }

    /**
     * @param string $path the epub file extract destination
     * @param null|array|string $fileType file mimetype will extract or except
     * @param bool $except
     * @throws \Exception
     */
    public function extract($path, $fileType = null, $except = false) {
        if ( !is_dir($path) ) {
            throw new \Exception('invalid folder given!');
        }
        $this->open();

        $allMainfest = $this->manifest;
        $fileLimit = null;
        if ( !is_null($fileType) && is_string($fileType)) {
            $mainfest = $this->getManifestByType($fileType);
            if ( $mainfest !== false ) {
                $fileLimit = array_column($mainfest, 'href');
            }
        } else if (is_array($fileType)) {
            $fileLimit = $fileType;
        }

        if ($except === true && !is_null($fileLimit)) {
            $fileLimit = array_diff($allMainfest, $fileLimit);
        }

        if (is_null($fileLimit)) {
            $this->zipArchive->extractTo($path);
        } else {
            $this->zipArchive->extractTo($path, array_values($fileLimit));
        }

        $needReplacePath = array_values(array_column(array_filter($this->manifest, function($item) {
            return $item['media-type'] === 'application/xhtml+xml';
        }),'href'));


        if (!is_null($fileLimit)) {
            $needReplacePath = array_intersect($needReplacePath, $fileLimit);
        }

        foreach ($needReplacePath as $file) {
            $this->_replaceExtractFile( implode($this->directorySeparator, [rtrim($path, $this->directorySeparator), $file]), $file);
        }

        $this->close();
    }


    /**
     * @param $realPath
     * @param $fileBasePath
     * @throws \Exception
     */
    private function _replaceExtractFile($realPath, $fileBasePath) {
        if ( file_exists($realPath) && is_file($realPath) && is_readable($realPath) && is_writable($realPath)) {
            $str = file_get_contents($realPath);


            // replace image url
            $str = preg_replace_callback('/(\s(?:xlink:href|src)\s*=\s*["\']?)([^"\'\s>]*?)(["\'\s>])/', function($matches) use($fileBasePath){
                $img = Util::directoryConcat($fileBasePath, urldecode($matches[2]), True);

                $element = null;
                foreach ($this->manifest as $key => $value) {
                    $mainestUrl = $value['href'];
                    if ($mainestUrl === $img) {
                        $element = $value;
                        break;
                    }
                }
                if (!is_null($element)) {
                    return $matches[1].$this->imageWebRoot.'/'.$img.$matches[3];
                }
                return '';
            }, $str);


            // replace link href
            $str = preg_replace_callback('/(\shref\s*=\s*["\']?)([^"\'\s>]*?)(["\'\s>])/', function($matches) use($fileBasePath){
                $linkparts = isset($matches[2]) ? explode("#", Util::directoryConcat($fileBasePath, urldecode($matches[2]), true)): [];
                $link    = array_shift($linkparts) ?? '';
                $element   = null;

                if ($link) {
                    foreach ($this->manifest as $key => $value) {
                        if(explode('#', $value['href'])[0] === $link) {
                            $element = $value;
                            break;
                        }
                    }
                }

                if (count($linkparts)) {
                    $link .= '#'.implode( '#',$linkparts);
                }

                // include only images from manifest
                if ($element) {
                    return $matches[1].$this->linkWebRoot."/".$link.$matches[3];
                }
                return $matches[1].$matches[2].$matches[3];
            }, $str);

            file_put_contents($realPath, $str);
        } else {
            throw new \Exception("change $realPath error");
        }
    }

}
