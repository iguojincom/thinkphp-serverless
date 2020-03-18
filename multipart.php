<?php

class MultipartFormDataParser
{
    /**
     * Returns the maximum size of an uploaded file as configured in php.ini.
     *
     * @return int The maximum size of an uploaded file in bytes
     */
    public static function getMaxFilesize()
    {
        $sizePostMax = self::parseFilesize(ini_get('post_max_size'));
        $sizeUploadMax = self::parseFilesize(ini_get('upload_max_filesize'));

        return min($sizePostMax ?: PHP_INT_MAX, $sizeUploadMax ?: PHP_INT_MAX);
    }

    /**
     * Returns the given size from an ini value in bytes.
     */
    private static function parseFilesize($size): int
    {
        if ('' === $size) {
            return 0;
        }

        $size = strtolower($size);

        $max = ltrim($size, '+');
        if (0 === strpos($max, '0x')) {
            $max = \intval($max, 16);
        } elseif (0 === strpos($max, '0')) {
            $max = \intval($max, 8);
        } else {
            $max = (int) $max;
        }

        switch (substr($size, -1)) {
            case 't':$max *= 1024;
            // no break
            case 'g':$max *= 1024;
            // no break
            case 'm':$max *= 1024;
            // no break
            case 'k':$max *= 1024;
        }

        return $max;
    }

    /**
     * @var int upload file max size in bytes.
     */
    private $uploadFileMaxSize;

    /**
     * @var int maximum upload files count.
     */
    private $uploadFileMaxCount;

    /**
     * @var resource[] resources for temporary file, created during request parsing.
     */
    private $tmpFileResources = [];

    /**
     * @return int upload file max size in bytes.
     */
    public function getUploadFileMaxSize(): int
    {
        if ($this->uploadFileMaxSize === null) {
            $this->uploadFileMaxSize = static::getMaxFilesize();
        }

        return $this->uploadFileMaxSize;
    }

    /**
     * @param  int  $uploadFileMaxSize upload file max size in bytes.
     * @return static self reference.
     */
    public function setUploadFileMaxSize(int $uploadFileMaxSize): self
    {
        $this->uploadFileMaxSize = $uploadFileMaxSize;

        return $this;
    }
    /**
     * @return int maximum upload files count.
     */
    public function getUploadFileMaxCount(): int
    {
        if ($this->uploadFileMaxCount === null) {
            $this->uploadFileMaxCount = ini_get('max_file_uploads');
        }

        return $this->uploadFileMaxCount;
    }
    /**
     * @param int $uploadFileMaxCount maximum upload files count.
     * @return static self reference.
     */
    public function setUploadFileMaxCount(int $uploadFileMaxCount): self
    {
        $this->uploadFileMaxCount = $uploadFileMaxCount;

        return $this;
    }

    /**
     * Parses given request in case it holds 'multipart/form-data' content.
     * This method is immutable: it leaves passed request object intact, creating new one for parsed results.
     * This method returns original request in case it does not hold appropriate content type or has empty body.
     */
    public function parse(array $headers, string $rawBody)
    {
        $contentType = $headers['content-type'] ?? '';
        if (stripos($contentType, 'multipart/form-data') === false) {
            return false;
        }
        if (!preg_match('/boundary=(.*)$/is', $contentType, $matches)) {
            return false;
        }
        $boundary = $matches[1];

        if (empty($rawBody)) {
            return false;
        }

        $bodyParts = preg_split('/\\R?-+' . preg_quote($boundary, '/') . '/s', $rawBody);
        array_pop($bodyParts); // last block always has no data, contains boundary ending like `--`

        $bodyParams = [];
        $uploadedFiles = [];
        $filesCount = 0;
        foreach ($bodyParts as $bodyPart) {
            if (empty($bodyPart)) {
                continue;
            }

            [$headers, $value] = preg_split('/\\R\\R/', $bodyPart, 2);
            $headers = $this->parseHeaders($headers);

            if (!isset($headers['content-disposition']['name'])) {
                continue;
            }

            if (isset($headers['content-disposition']['filename'])) {
                // file upload:
                if ($filesCount >= $this->getUploadFileMaxCount()) {
                    continue;
                }

                $clientFilename = $headers['content-disposition']['filename'];
                $clientMediaType = $headers['content-type'] ?? 'application/octet-stream';
                $size = mb_strlen($value, '8bit');
                $error = UPLOAD_ERR_OK;
                $tempFilename = '';

                if ($size > $this->getUploadFileMaxSize()) {
                    $error = UPLOAD_ERR_INI_SIZE;
                } else {
                    $tmpResource = tmpfile();

                    if ($tmpResource === false) {
                        $error = UPLOAD_ERR_CANT_WRITE;
                    } else {
                        $tmpResourceMetaData = stream_get_meta_data($tmpResource);
                        $tmpFileName = $tmpResourceMetaData['uri'];

                        if (empty($tmpFileName)) {
                            $error = UPLOAD_ERR_CANT_WRITE;
                            @fclose($tmpResource);
                        } else {
                            fwrite($tmpResource, $value);
                            $tempFilename = $tmpFileName;
                            $this->tmpFileResources[] = $tmpResource; // save file resource, otherwise it will be deleted
                        }
                    }
                }

                $this->addValue(
                    $uploadedFiles,
                    $headers['content-disposition']['name'],
                    $this->createUploadedFile(
                        $tempFilename,
                        $clientFilename,
                        $clientMediaType,
                        $error
                    )
                );

                $filesCount++;
            } else {
                // regular parameter:
                $this->addValue($bodyParams, $headers['content-disposition']['name'], $value);
            }
        }

        return $this->newRequest($bodyParams, $uploadedFiles);
    }
    /**
     * Creates new request instance from original one with parsed body parameters and uploaded files.
     * This method is called only in case original request has been successfully parsed as 'multipart/form-data'.
     *
     * @param  \Illuminate\Http\Request  $originalRequest original request instance being parsed.
     * @param  array  $bodyParams parsed body parameters.
     * @param  array  $uploadedFiles parsed uploaded files.
     * @return \Illuminate\Http\Request new request instance.
     */
    protected function newRequest(array $bodyParams, array $uploadedFiles)
    {
        $request = [];
        $request['files'] = $uploadedFiles;
        $request['params'] = $bodyParams;
        return $request;
    }
    /**
     * Creates new uploaded file instance.
     *
     * @param  string  $tempFilename the full temporary path to the file.
     * @param  string  $clientFilename the filename sent by the client.
     * @param  string|null  $clientMediaType the media type sent by the client.
     * @param  int|null  $error the error associated with the uploaded file.
     */
    protected function createUploadedFile(string $tempFilename, string $clientFilename, string $clientMediaType = null, int $error = null)
    {
        return [
            'name' => $clientFilename,
            'type' => $clientMediaType,
            'tmp_name' => $tempFilename,
            'error' => $error,
            'size' => (new \SplFileInfo($tempFilename))->getSize(),
        ];
    }

    /**
     * Parses content part headers.
     *
     * @param  string  $headerContent headers source content
     * @return array parsed headers.
     */
    private function parseHeaders(string $headerContent): array
    {
        $headers = [];
        $headerParts = preg_split('/\\R/s', $headerContent, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($headerParts as $headerPart) {
            if (strpos($headerPart, ':') === false) {
                continue;
            }

            [$headerName, $headerValue] = explode(':', $headerPart, 2);
            $headerName = strtolower(trim($headerName));
            $headerValue = trim($headerValue);

            if (strpos($headerValue, ';') === false) {
                $headers[$headerName] = $headerValue;
            } else {
                $headers[$headerName] = [];
                foreach (explode(';', $headerValue) as $part) {
                    $part = trim($part);
                    if (strpos($part, '=') === false) {
                        $headers[$headerName][] = $part;
                    } else {
                        [$name, $value] = explode('=', $part, 2);
                        $name = strtolower(trim($name));
                        $value = trim(trim($value), '"');
                        $headers[$headerName][$name] = $value;
                    }
                }
            }
        }

        return $headers;
    }

    /**
     * Adds value to the array by input name, e.g. `Item[name]`.
     *
     * @param  array  $array array which should store value.
     * @param  string  $name input name specification.
     * @param mixed $value value to be added.
     */
    private function addValue(&$array, $name, $value): void
    {
        $nameParts = preg_split('/\\]\\[|\\[/s', $name);
        $current = &$array;
        foreach ($nameParts as $namePart) {
            $namePart = trim($namePart, ']');
            if ($namePart === '') {
                $current[] = [];
                $keys = array_keys($current);
                $lastKey = array_pop($keys);
                $current = &$current[$lastKey];
            } else {
                if (!isset($current[$namePart])) {
                    $current[$namePart] = [];
                }
                $current = &$current[$namePart];
            }
        }
        $current = $value;
    }
    /**
     * Closes all temporary files associated with this parser instance.
     *
     * @return static self instance.
     */
    public function closeTmpFiles(): self
    {
        foreach ($this->tmpFileResources as $resource) {
            @fclose($resource);
        }

        $this->tmpFileResources = [];

        return $this;
    }
    /**
     * Destructor.
     * Ensures all possibly created during parsing temporary files are gracefully closed and removed.
     */
    public function __destruct()
    {
        $this->closeTmpFiles();
    }
}

// $body = <<<EOF
// --cc51ab821e27f5818ba3662ab706787e6bcc6e4d
// Content-Disposition: form-data; name="appid"
// Content-Length: 20

// 8ovpzwzBKFzz88y60N22
// --cc51ab821e27f5818ba3662ab706787e6bcc6e4d
// Content-Disposition: form-data; name="file2"; filename="upfile.txt"
// Content-Length: 6
// Content-Type: text/plain

// upfile
// --cc51ab821e27f5818ba3662ab706787e6bcc6e4d
// Content-Disposition: form-data; name="file3"; filename="upfile.txt"
// Content-Length: 6
// Content-Type: text/plain

// upfile
// --cc51ab821e27f5818ba3662ab706787e6bcc6e4d--

// EOF;

// $header = [
//     "content-length" => "516",
//     "content-type" => "multipart/form-data; boundary=cc51ab821e27f5818ba3662ab706787e6bcc6e4d",
// ];

// (new MultipartFormDataParser)->parse($header, $body);
