<?php

declare(strict_types=1);

// Mini App API: player picker (bootstrap/search/submit) and the tournament form
// (create_bootstrap/create_submit, edit_*) and the team form (team_*). Auth is Telegram initData HMAC, not the webhook secret.

use P1Bot\App\CreateTournamentForm;
use P1Bot\App\TeamForm;
use P1Bot\App\WebAppFormError;
use P1Bot\Bootstrap;
use P1Bot\Domain\Exception\DomainError;
use P1Bot\Support\Logger;

require dirname(__DIR__) . '/autoload.php';
$config = require dirname(__DIR__) . '/config/config.php';
$logger = Logger::fromConfig($config);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
	http_response_code(204);
	exit;
}

function webapp_read_body(): array {
	$raw = (string) file_get_contents('php://input');
	if ($raw === '') {
		return [];
	}
	$json = json_decode($raw, true);
	return is_array($json) ? $json : [];
}

function webapp_fail(int $status, string $error): void {
	http_response_code($status);
	echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
	exit;
}

$body = webapp_read_body();
$op = (string) ($body['op'] ?? $_GET['op'] ?? '');
$token = (string) ($body['ctx'] ?? $_GET['ctx'] ?? '');
$init = (string) ($body['init_data'] ?? $_GET['init_data'] ?? '');
if ($init === '' && isset($_SERVER['HTTP_X_TELEGRAM_INIT_DATA'])) {
	$init = (string) $_SERVER['HTTP_X_TELEGRAM_INIT_DATA'];
}

if ($op === '' || $token === '') {
	if (str_starts_with($op, 'team_')) {
		http_response_code(400);
		echo json_encode((new WebAppFormError(TeamForm::EXPIRED_TEXT, '', WebAppFormError::EXPIRED))->toArray(), JSON_UNESCAPED_UNICODE);
		exit;
	}
	if ((str_starts_with($op, 'create_') || str_starts_with($op, 'edit_'))) {
		http_response_code(400);
		echo json_encode(
			(new WebAppFormError(str_starts_with($op, 'edit_') ? CreateTournamentForm::EDIT_EXPIRED_TEXT : CreateTournamentForm::EXPIRED_TEXT, '', WebAppFormError::EXPIRED))->toArray(),
			JSON_UNESCAPED_UNICODE,
		);
		exit;
	}
	webapp_fail(400, 'Нет контекста подбора.');
}

try {
	$app = Bootstrap::fromConfig($config, $logger);
	$app->ensureOwner();
	$picker = $app->webapp_picker;
	if ($op === 'create_bootstrap') {
		$result = $app->create_form->bootstrap($init, $token);
	} elseif ($op === 'create_submit') {
		$form = $body['form'] ?? [];
		$result = $app->create_form->submit($init, $token, is_array($form) ? $form : []);
	} elseif ($op === 'edit_bootstrap') {
		$result = $app->create_form->editBootstrap($init, $token);
	} elseif ($op === 'edit_submit') {
		$form = $body['form'] ?? [];
		$result = $app->create_form->editSubmit($init, $token, is_array($form) ? $form : []);
	} elseif ($op === 'team_bootstrap') {
		$result = $app->team_form->bootstrap($init, $token);
	} elseif ($op === 'team_search') {
		$result = $app->team_form->search($init, $token, (string) ($body['q'] ?? ''));
	} elseif ($op === 'team_submit' || $op === 'team_contacts') {
		$form = $body['form'] ?? [];
		$form = is_array($form) ? $form : [];
		$result = $op === 'team_submit'
			? $app->team_form->submit($init, $token, $form)
			: $app->team_form->contacts($init, $token, $form);
	} elseif ($op === 'team_leave') {
		$result = $app->team_form->leave($init, $token);
	} elseif ($op === 'team_disband') {
		$result = $app->team_form->disband($init, $token);
	} elseif ($op === 'bootstrap') {
		$result = $picker->bootstrap($init, $token);
	} elseif ($op === 'search') {
		$query = (string) ($body['q'] ?? $_GET['q'] ?? '');
		$result = $picker->search($init, $token, $query);
	} elseif ($op === 'submit') {
		$ids = $body['player_ids'] ?? [];
		if (!is_array($ids)) {
			webapp_fail(400, 'Никого не выбрали.');
		}
		$level = null;
		if (isset($body['level']) && $body['level'] !== null && $body['level'] !== '') {
			$level = (int) $body['level'];
		}
		$result = $picker->submit($init, $token, $ids, $level);
	} else {
		webapp_fail(400, 'Неизвестная команда.');
	}
	echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (WebAppFormError $e) {
	$logger->info('webapp form: ' . $e->reason . ' ' . $e->field . ' ' . $e->getMessage());
	http_response_code(400);
	echo json_encode($e->toArray(), JSON_UNESCAPED_UNICODE);
} catch (DomainError $e) {
	$logger->info('webapp: ' . $e->getMessage());
	webapp_fail(400, $e->getMessage());
} catch (Throwable $e) {
	$logger->exception($e, 'webapp_api');
	webapp_fail(500, 'Не получилось обработать запрос. Попробуй ещё раз.');
}
