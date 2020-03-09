<?php

namespace http23\MailRu;

use GuzzleHttp\Exception\GuzzleException;
use http\MailRu\CloudMail;

/**
 * Trait AuthTrait
 * @package http23\MailRu
 * @mixin CloudMail
 */
trait AuthTrait
{
    protected $userAgent = 'Mozilla / 5.0(Windows; U; Windows NT 5.1; en - US; rv: 1.9.0.1) Gecko / 2008070208 Firefox / 3.0.1';
    protected $isAuth;
    protected $email;
    protected $login;
    protected $password;
    protected $domain;

    protected $token;
    protected $tokenLifeTime;

    /**
     * @return $this
     * @throws GuzzleException
     */
    protected function auth()
    {

        $this->request('https://auth.mail.ru/cgi-bin/auth', 'POST', [
            'Login'    => $this->login,
            'Password' => $this->password,
            'Domain'   => $this->domain,
        ], 'multipart', false);

        $this->isAuth = true;
        $this->client->request('GET', 'https://cloud.mail.ru');
        $this->fetchToken();

        return $this;
    }


    /**
     * @throws GuzzleException
     */
    public function fetchToken()
    {
        $res = json_decode($this->client->request('GET', self::FETCH_TOKEN_URL, [
            'form_params' => [
                'api'     => 'v2',
                'email'   => $this->login,
                'x-email' => $this->login,
            ],
        ])->getBody()->getContents());

        $this->token         = $res->body->token;
        $this->tokenLifeTime = $res->time;
        $this->email         = $res->email;
    }
}