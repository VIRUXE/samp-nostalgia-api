<?php

function split_zero($string) {
    $nullPos = strpos($string, "\0");
    return $nullPos !== false ? substr($string, 0, $nullPos) : $string;
}

class IMGArchive {
    private $filestream;
    private $version;
    public $numberOfEntries;
    public $entries = [];
    private $pendingAdditions = [];
    private $pendingDeletions = [];
    private $lastOffset = 0;

    const SECTOR_SIZE = 2048;
    const HEADER_SIZE = 8;
    const DENTRY_SIZE = 32;

    public function __construct($filepath) {
        $this->filestream = fopen($filepath, 'r+b');
        $this->loadHeader();
        $this->loadEntries();
    }

    public function __destruct() {
        fclose($this->filestream);
    }

    private function loadHeader() {
        fseek($this->filestream, 0, SEEK_SET);
        $header = fread($this->filestream, self::HEADER_SIZE);
        $header = unpack('a4version/VnumberOfEntries', $header);

        $this->version         = $header['version'];
        $this->numberOfEntries = $header['numberOfEntries'];
    }

    private function loadEntries() {
        fseek($this->filestream, self::HEADER_SIZE, SEEK_SET);

        for ($i = 0; $i < $this->numberOfEntries; $i++) {
            $data      = fread($this->filestream, self::DENTRY_SIZE);
            $entryData = unpack('Voffset/vstreaming/vsize/a24name', $data);
            $name      = split_zero($entryData['name']);

            $this->entries[$name] = $entryData;
            $this->lastOffset = max($this->lastOffset, $entryData['offset']);
        }
    }

    public function exists($name) {
        return isset($this->entries[$name]);
    }

    public function add($name, $data) {
        $this->pendingAdditions[$name] = $data;
    }

    public function delete($name) {
        $this->pendingDeletions[$name] = true;
    }

    public function replace($name, $data) {
        $this->delete($name);
        $this->add($name, $data);
    }

    public function extractToMemory($name) {
        if (!isset($this->entries[$name])) return false;
    
        $entry  = $this->entries[$name];
        $offset = $entry['offset'] * self::SECTOR_SIZE;
        $size   = $entry['streaming'] * self::SECTOR_SIZE;
    
        fseek($this->filestream, $offset, SEEK_SET);
        $data = fread($this->filestream, $size);
    
        return $data;
    }

    public function extractToFile($name, $output_dir, $callback = null) {
        $entryData = $this->extractToMemory($name);
    
        if ($entryData === false) return false;
    
        // Check if the output directory exists and create it if necessary
        if (!file_exists($output_dir)) mkdir($output_dir, 0777, true);
    
        file_put_contents($output_dir . '/' . $name, $entryData);
    
        // Call the callback function, if provided
        if (is_callable($callback)) call_user_func($callback, $name, $output_dir);
    
        return true;
    }

    public function save() {
        $tempStream = fopen('php://temp', 'w+b');

        fwrite($tempStream, $this->version);
        fwrite($tempStream, pack('V', $this->numberOfEntries));

        foreach ($this->entries as $name => $entry) {
            if (isset($this->pendingDeletions[$name])) continue;

            $serializedEntry = $this->serializeEntry($entry);
            fwrite($tempStream, $serializedEntry);
        }

        foreach ($this->pendingAdditions as $name => $data) {
            $newEntry = [
                'offset'    => ++$this->lastOffset,
                'streaming' => strlen($data) / self::SECTOR_SIZE,
                'size'      => strlen($data),
                'name'      => $name
            ];

            $serializedEntry = $this->serializeEntry($newEntry);
            fwrite($tempStream, $serializedEntry);
        }

        fseek($tempStream, 0, SEEK_SET);
        fseek($this->filestream, 0, SEEK_SET);

        while (!feof($tempStream)) {
            $data = fread($tempStream, self::SECTOR_SIZE);
            fwrite($this->filestream, $data);
        }

        ftruncate($this->filestream, ftell($this->filestream));

        fclose($tempStream);

        $this->pendingAdditions = [];
        $this->pendingDeletions = [];
    }

    private function serializeEntry($entry) {
        return pack('Vvvva24', $entry['offset'], $entry['streaming'], $entry['size'], $entry['name']);
    }
}