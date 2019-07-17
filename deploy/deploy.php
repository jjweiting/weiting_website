<?php

namespace Deployer;

require_once __DIR__.'/../vendor/deployer/deployer/recipe/laravel.php';
require_once __DIR__.'/../vendor/deployer/recipes/recipe/slack.php';
require_once __DIR__.'/../vendor/deployer/recipes/recipe/rsync.php';

date_default_timezone_set('Asia/Taipei');
$host_filename = 'laravel_hosts.yml';
$latest_timestamp = '2017-07-01 19:00';

$host_path = __DIR__."/stage/{$host_filename}";
if (!file_exists($host_path)) {
    echo "{$host_path} not exists.";
    exit();
}
if (filemtime($host_path) < strtotime($latest_timestamp)) {
    echo "You have to download latest {$server_filename} after {$latest_timestamp}.";
    exit();
}

// Configuration

set('bin/pip', function () {
    return locateBinaryPath('pip3.6');
});

set('php-fpm-service', function () {
    return run("service --status-all | grep php | awk -v N=4 '{print \$N}'");
});

set('repository', 'git@github.com:jjweiting/weiting_website.git');

set('ssh_type', 'native');
set('ssh_multiplexing', true);

// set('slack_title', 'laravel');
// set('slack_webhook', 'https://hooks.slack.com/services/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');

set('cleanup_use_sudo', true);

add('shared_files', [
    'script/recommender/blog/knn.pkl',
    'script/recommender/city/knn/data/files.pkl',
]);

// Servers
inventory($host_path);

// Tasks
task('deploy:check_env', function () {
    if (runLocally('echo $APP_ENV') === 'testing') { // circleci
        return;
    }
    $stage = get('stage');
    $deploy_path = get('deploy_path');
    $shared_path = "{$deploy_path}/shared";
    if (!test("[ -f {$shared_path}/.env ]")) {
        writeln('<info>✔</info> .env file not exist');

        return;
    }

    download("{$shared_path}/.env", '../.env.remote');

    $dotenv = \Dotenv\Dotenv::create(__DIR__, '../.env.remote');
    $dotenv->overload();

    $remote_env = $_ENV;

    $dotenv = \Dotenv\Dotenv::create(__DIR__, "../.env.{$stage}");
    $dotenv->overload();
    $local_env = $_ENV;

    $diff_arr = array_merge(array_diff($local_env, $remote_env), array_diff($remote_env, $local_env));
    if (empty($diff_arr)) {
        return;
    }

    writeln('');
    foreach ($diff_arr as $key => $value) {
        $remote = $remote_env[$key] ?? '';
        $local = $local_env[$key] ?? '';
        write("<info>key: {$key}</info>\r\nlocal : {$local}\r\nremote: {$remote}\r\n\r\n");
    }

    $yes_no = askConfirmation(".env 不相同，確定要上傳該 .env.{$stage}");
    if ($yes_no === false) {
        throw new \RuntimeException('user cancel');
    }
})->desc('Make sure that you want to upload env file');

task('deploy:upload_env', function () {
    if (runLocally('echo $APP_ENV') === 'testing') { // circleci
        return;
    }

    $stage = get('stage');
    $deploy_path = get('deploy_path');
    $shared_path = "{$deploy_path}/shared";

    if (!test("[ -f {$shared_path}/.env ]")) {
        writeln('<info>✔</info> .env file not exist');

        return;
    }

    upload("../.env.{$stage}", "{$shared_path}/.env");
    run("chmod 777 {$shared_path}/.env");
})->desc('Upload corresonding env file');

task('deploy:pip', function () {
    $output = run('cd {{release_path}} && sudo {{bin/pip}} install -r {{deploy_path}}/current/script/requirements.txt');
    writeln("<info>{$output}</info>");
})->desc('Install python packages');

task('php-fpm:restart', function () {
    $hostname = get('hostname');
    // if ($hostname === 'w1.tripnotice.com' || $hostname === 'p1.tripnotice.com') {
    //     $service_name = get('php-fpm-service');
    //     run("sudo /usr/sbin/service {$service_name} reload");
    // }
})->desc('Restart PHP-FPM service');

task('queue:restart', function () {
    $hostname = get('hostname');
    // if ($hostname === 'job.tripnotice.com') {
    //     run('{{bin/php}} {{release_path}}/artisan queue:restart');
    // }
})->desc('Restart queue worker');

task('artisan:recommender:restart', function () {
    // if ($hostname === 'p1.tripnotice.com') {
    //     run('{{bin/php}} {{release_path}}/artisan recommender restart');
    // }
})->desc('Restart Recommender');

task('artisan:sync:story', function () {
    run('{{bin/php}} {{release_path}}/artisan sync:story');
})->desc('Execute artisan sync:story');

task('deploy', [
    'deploy:prepare',
    'deploy:check_env',
    'deploy:lock',
    'deploy:release',
    'deploy:update_code',
    'deploy:upload_env',
    'deploy:shared',
    'deploy:vendors',
    'deploy:writable',
    // 'artisan:view:clear',
    'artisan:config:cache',
    'artisan:optimize',
    'deploy:symlink',
    'deploy:pip',
    'deploy:unlock',
    'cleanup',
]);

// before('deploy', 'slack:notify');
after('deploy:symlink', 'php-fpm:restart');
// after('deploy', 'queue:restart');
// after('deploy', 'slack:notify:success');
