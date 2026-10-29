<?php

declare(strict_types=1);

namespace WynForms\Controllers;

use WynForms\App;
use WynForms\Forms;
use WynForms\Http\Request;
use WynForms\Http\Response;

/**
 * The public form endpoint: POST /f/{key}
 */
final class PublicController
{
    public function __construct(private readonly App $app)
    {
    }

    public function submit(Request $r, string $key): Response
    {
        $form = $this->app->forms->findByKey($key);
        $cors = $this->cors($form, $r);

        if ($r->method === 'OPTIONS') {
            return new Response(204, '', $cors + [
                'Access-Control-Allow-Methods' => 'POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, Accept, X-Requested-With',
                'Access-Control-Max-Age'       => '86400',
            ]);
        }

        if ($form === null) {
            return $this->reply($r, false, 'This form doesn’t exist. Check the form URL.', 404, $cors);
        }

        $result = $this->app->receiver->handle($form, $r);
        if ($result['notify'] !== null) {
            $this->app->defer($result['notify']);
        }
        $this->app->defer(fn () => $this->app->cleanup());

        if ($r->wantsJson()) {
            return $this->reply($r, $result['ok'], $result['message'], $result['status'], $cors, $result['id']);
        }
        if (!$result['ok']) {
            return $this->reply($r, false, $result['message'], $result['status'], $cors);
        }

        $back = (string) $r->header('referer');
        $target = $result['redirect'] ?? $this->app->url('f/' . $key . '/thanks') . ($back !== '' ? '?back=' . rawurlencode($back) : '');

        return Response::redirect($target, 303);
    }

    public function thanks(Request $r, string $key): Response
    {
        $form = $this->app->forms->findByKey($key);
        $back = $r->input('back');
        if (!preg_match('#^https?://#i', $back)) {
            $back = '';
        }

        return Response::html($this->app->view()->render('public/thanks', ['form' => $form, 'back' => $back]));
    }

    public function endpointInfo(Request $r, string $key): Response
    {
        $form = $this->app->forms->findByKey($key);
        if ($form === null) {
            return $this->notFound();
        }

        return Response::html($this->app->view()->render('public/message', [
            'heading' => 'Form endpoint.',
            'text'    => 'This address receives submissions from a website form. Point a form’s action here with method="POST".',
            'back'    => '',
        ]));
    }

    public function notFound(): Response
    {
        return Response::html($this->app->view()->render('public/message', [
            'heading' => 'Not found.',
            'text'    => 'There’s nothing at this address.',
            'back'    => '',
        ]), 404);
    }

    private function reply(Request $r, bool $ok, string $message, int $status, array $cors, ?int $id = null): Response
    {
        if ($r->wantsJson()) {
            $res = Response::json(array_filter(['ok' => $ok, 'message' => $message, 'id' => $id], fn ($v) => $v !== null), $status);
            $res->headers = array_replace($res->headers, $cors, ['Cache-Control' => 'no-store']);

            return $res;
        }

        $back = (string) $r->header('referer');

        return Response::html($this->app->view()->render('public/message', [
            'heading' => 'Not sent.',
            'text'    => $message,
            'back'    => preg_match('#^https?://#i', $back) ? $back : '',
        ]), $status);
    }

    /** Allow browsers on the form's allowed sites (or any site, when none are listed) to read the JSON reply. */
    private function cors(?array $form, Request $r): array
    {
        $origin = (string) $r->header('origin');
        if ($form === null || $origin === '') {
            return [];
        }
        $allowed = Forms::origins($form);
        if ($allowed === []) {
            return ['Access-Control-Allow-Origin' => '*'];
        }
        $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
        foreach ($allowed as $a) {
            if ($host === $a || str_ends_with($host, '.' . $a)) {
                return ['Access-Control-Allow-Origin' => $origin, 'Vary' => 'Origin'];
            }
        }

        return [];
    }
}
