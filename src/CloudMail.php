<?php

namespace http\MailRu;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use http23\MailRu\AuthTrait;
use SplFileObject;

/**
 * Client to work with https://cloud.mail.ru
 *
 * @author Voronin Gosha <voronin.gosha@bk.ru>
 * @mixin AuthTrait
 */
class CloudMail
{
    const VERSION_API     = 2;
    const CLOUD_DOMAIN    = 'https://cloud.mail.ru/api/v2';
    const FETCH_TOKEN_URL = 'https://cloud.mail.ru/api/v2/tokens/csrf';
    const UPLOAD_URL      = 'https://cld-upload9.cloud.mail.ru/upload-web/';
    const DOWNLOAD_URL    = 'https://cloclo17.datacloudmail.ru/';

    protected $client;

    use AuthTrait;

    /**
     * CloudMail constructor.
     *
     * @param $login
     * @param $password
     * @param $domain
     *
     * @throws GuzzleException
     */
    public function __construct($login, $password, $domain)
    {
        $this->login    = $login;
        $this->password = $password;
        $this->domain   = $domain;

        $this->client = new Client(
            [
                'http_errors' => false,
                'headers'     => [
                    'Accept'     => '*/*',
                    'User-Agent' => $this->userAgent,
                ],
                'cookies'     => new CookieJar(),
            ]);

        $this->auth();
    }


    /**
     * Move the file
     *
     * @param string $path      Path to file
     * @param string $newFolder Path to new folder
     *
     * @return mixed Request response
     * @throws GuzzleException
     */
    public function move($path, $newFolder)
    {
        return $this->request('/file/move', 'POST', [
            'folder'   => $newFolder,
            'conflict' => 'rename',
            'home'     => $path,
        ]);
    }

    /**
     * Copy file to folder
     *
     * @param string $path         Path the file
     * @param string $copyToFolder Copy to this folder
     *
     * @return mixed
     * @throws GuzzleException
     */
    public function copy($path, $copyToFolder)
    {
        return $this->request('/file/copy', 'POST', [
            'folder'   => $copyToFolder,
            'conflict' => 'rename',
            'home'     => $path,
        ]);
    }


    /**
     * Delete this file
     *
     * @param string $path Path this file which need delete
     *
     * @return mixed
     * @throws GuzzleException
     */
    public function delete($path)
    {
        return $this->request('/file/remove', 'POST', ['home' => $path]);
    }


    /**
     * Get all files which in this folder
     *
     * @param $directory
     *
     * @return mixed
     * @throws GuzzleException
     */
    public function files($directory)
    {
        return $this->request('/folder', 'GET', [
            'home' => $directory,
            'sort' => '{"type":"name","order":"asc"}',
        ]);
    }


    /**
     * @param $path
     * @param $content
     *
     * @return mixed
     * @throws GuzzleException
     */
    public function createFile($path, $content)
    {
        $tempFile = tmpfile();
        fwrite($tempFile, $content);

        $tempFilePath = stream_get_meta_data($tempFile);
        $file         = new SplFileObject($tempFilePath['uri']);

        return $this->upload($file, $path);
    }


    /**
     * Create a folder in Cloud
     *
     * @param string $path Path new folder
     *
     * @return mixed
     * @throws GuzzleException
     */
    public function createFolder($path)
    {
        return $this->request('/folder/add', 'GET', [
            'home' => $path,
        ]);
    }


    /**
     * Rename file
     *
     * @param string $path
     * @param string $name
     *
     * @return mixed
     * @throws GuzzleException
     */
    public function rename($path, $name)
    {
        return $this->request('/file/rename', 'GET', [
            'home' => $path,
            'name' => $name,
        ]);
    }


    /**
     * Uploads your files in Cloud
     *
     * @param SplFileObject $file
     * @param string|null   $filename
     * @param string        $saveFolder
     *
     * @return mixed
     * @throws GuzzleException
     */
    public function upload(SplFileObject $file, $filename = null, $saveFolder = '/')
    {
        $fileName = ($filename == null) ? $file->getBasename() : $filename;
        $fileSize = filesize($file->getRealPath());

        $params = [
            'query'   => [
                'cloud_domain' => 2,
                'x-email'      => $this->email,
            ],
            'body'    => $file->fread($fileSize),
            'headers' => [
                'Content-Disposition' => 'form-data; name="file"; filename="' . $fileName . '"',
                'Content-Type'        => 'multipart/form-data',
            ],
        ];

        return $this->confirmUpload(
            $saveFolder,
            $fileName,
            $this->client->request('PUT', self::UPLOAD_URL, $params)->getBody()->getContents(),
            $fileSize
        );
    }


    /**
     * Download your files of Cloud
     *
     * @param string $path     File which the your want download
     * @param string $savePath Local Path for save the file
     *
     * @return bool
     * @throws GuzzleException
     */
    public function download($path, $savePath)
    {
        $res = $this->client->request('GET', self::DOWNLOAD_URL . "get{$path}", ['sink' => $savePath]);

        return $res->getStatusCode() === 200;
    }


    /**
     * Set publish flag a file or folder
     *
     * @param string $path
     *
     * @return mixed
     * @throws GuzzleException
     */
    public function publishFile($path)
    {
        return $this->request('/file/publish', 'GET', [
            'home' => $path,
        ]);
    }


    /**
     * Set publish flag and get public link a file
     *
     * @param string $path Path file/folder
     *
     * @return string Public link the file
     * @throws GuzzleException
     */
    public function getLink($path)
    {
        $link = $this->publishFile($path)->body;

        return self::DOWNLOAD_URL . 'weblink/thumb/xw1/' . $link;
    }


    /**
     * @param $folder
     * @param $fileName
     * @param $hash
     * @param $fileSize
     *
     * @return mixed
     * @throws GuzzleException
     */
    protected function confirmUpload($folder, $fileName, $hash, $fileSize)
    {
        return $this->request('/file/add', 'POST', [
            'hash'     => $hash,
            'size'     => $fileSize,
            'home'     => rtrim($folder, '/') . '/' . $fileName,
            'conflict' => 'strict',
        ]);
    }

    /**
     * @param       $uri
     * @param       $method
     * @param array $params
     * @param null  $enctype
     * @param bool  $defaultParams
     *
     * @return mixed
     * @throws GuzzleException
     */
    protected function request($uri, $method, array $params, $enctype = null, $defaultParams = true)
    {
        $url = $this->formatUrl($uri);

        $default = $this->structureRequestParams();
        $payload = ($defaultParams) ? array_merge($default, $params) : $params;

        if ($enctype === 'multipart') {
            $params = $this->formatMultipartData($payload);
        } else {
            $params = (strtoupper($method) === 'GET') ? ['query' => $payload] : ['form_params' => $payload];
        }

        $res = $this->client->request($method, $url, $params);

        return json_decode($res->getBody()->getContents());
    }


    protected function formatUrl($uri)
    {
        return (strstr($uri, '://')) ? $uri : self::CLOUD_DOMAIN . $uri;
    }


    protected function formatMultipartData($data)
    {
        $result = [];
        foreach ($data as $key => $datum) {
            $result[] = [
                'name'     => $key,
                'contents' => $datum,
            ];
        }

        return ['multipart' => $result];
    }


    protected function structureRequestParams()
    {
        return [
            'home'    => null,
            'api'     => self::VERSION_API,
            'email'   => $this->email,
            'x-email' => $this->email,
            'token'   => $this->token,
            '_'       => $this->tokenLifeTime,
        ];
    }
}