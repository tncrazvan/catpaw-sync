<?php
use function Amp\async;
use function Amp\File\deleteFile;
use function Amp\File\exists;
use function Amp\File\read;
use function Amp\File\write;
use function Amp\Future\awaitAll;

use CatPaw\Attributes\Option;
use CatPaw\Container;

use function CatPaw\execute;
use function CatPaw\Text\foreground;

use function CatPaw\Text\nocolor;

use Psr\Log\LoggerInterface;

function main(
    #[Option("--environment")]
    string $env,
) {
    sync(env: $env);
}

function sync(string $env):void {
    $env    = realpath($env);
    $envDir = dirname($env);

    $red        = foreground(red: 220, green: 20, blue: 20);
    $green      = foreground(red: 150, green: 220);
    $blue       = foreground(red:0, green:180, blue:220, );
    $yellow     = foreground(red: 220, green: 150, blue: 0);
    $cian       = foreground(red: 50, green: 220, blue: 200);
    $darkYellow = foreground(red:150, green:100);
    

    /** @var LoggerInterface */
    $logger = Container::create(LoggerInterface::class);

    if (exists("./.sync.cache")) {
        $cache = yaml_parse(read("./.sync.cache"));
    } else {
        $cache = [];
    }

    /** @var array */
    $projects = $_ENV['projects'] ?? [];

    $root             = realpath($envDir);
    $libraries        = [];
    $versions         = [];
    $pushInstructions = [];
    $anyNuke          = false;
    foreach ($projects as $projectName => $projectProperties) {
        $library       = $projectProperties['library'] ?? $projectName;
        $versionString = preg_replace('/"/', '\\"', $projectProperties['version']);
        $nuke          = !!($projectProperties['nuke'] ?? false);
        if ($nuke) {
            $anyNuke = true;
        }
        $versionPieces = explode('.', $versionString);
        $version       = join('.', [$versionPieces[0] ?? '0',$versionPieces[1] ?? '0']);
        $message       = preg_replace('/"/', '\\"', $projectProperties['message'] ?? "Version $versionString");
        if (strpos($message, '`')) {
            $logger->error("Project $projectName contains backticks (`) in its message, this is not allowed.", ["message" => $message]);
            die(22);
        }
        $libraries[]        = $library;
        $versions[$library] = $version;
    }

    if ($anyNuke) {
        $logger->info("Removing cache due to nuking.");
        if (exists(".sync.cache")) {
            deleteFile(".sync.cache");
        }
    }

    $testsMessages = [];

    foreach ($projects as $projectName => $projectProperties) {
        $library       = $projectProperties['library'] ?? $projectName;
        $versionString = preg_replace('/"/', '\\"', $projectProperties['version']);
        /** @var bool */
        $nuke          = !!($projectProperties['nuke'] ?? false);
        $versionPieces = explode('.', $versionString);
        $version       = join('.', [$versionPieces[0] ?? '0',$versionPieces[1] ?? '0']);
        $message       = preg_replace('/"/', '\\"', $projectProperties['message'] ?? "Version $versionString");
        $message       = preg_replace('/\n|\s/', ' ', $message);

        $cwd              = "$root/$projectName";
        $composerFileName = "$cwd/composer.json";
        $composer         = json_decode(read($composerFileName));
        $canTest          = isset($composer->scripts->{'prod:test'}) && $composer->scripts->{'prod:test'};

        if ($nuke) {
            nuke($cwd);
            echo <<<TEXT
                {$red}Tags of "$projectName" have been nuked.\n
                TEXT;
            echo nocolor();
        }


        if (isset($composer->require)) {
            $versionChanges = 0;
            foreach ($composer->require as $composerLibrary => &$composerVersion) {
                if (in_array($composerLibrary, $libraries)) {
                    $newComposerVersion = '^'.$versions[$composerLibrary];
                    if ($composerVersion === $newComposerVersion) {
                        continue;
                    }
                    $composerVersion = $newComposerVersion;
                    $versionChanges++;
                }
            }
    
            if ($versionChanges > 0) {
                write($composerFileName, trim(json_encode($composer, JSON_PRETTY_PRINT)));
                write($composerFileName, trim(str_replace('\/', '/', read($composerFileName))));
            }
        }

        /**
         * @psalm-suppress MissingClosureReturnType
         */
        $pushInstructions[] = async(function() use (
            $cwd,
            $message,
            $versionString,
            $cache,
            $projectName,
            $canTest,
            &$testsMessages,
            $red,
            $blue,
            $yellow,
            $darkYellow,
            $green,
            $cian,
            $library,
            $nuke,
        ) {
            $versionChanged = ($cache["projects"][$projectName]["version"] ?? '') !== $versionString;
            if (!$versionChanged) {
                $unstagedChanges = execute("git status --porcelain", $cwd);

                if (trim((string)$unstagedChanges)) {
                    echo <<<TEXT
                        $yellow
                        $cian+++ Unstaged changes +++$yellow
                         >>> project $projectName ($green$library$yellow: $green$versionString$yellow)
                        $unstagedChanges
                        TEXT;
                }
                echo nocolor();
                return;
            }

            if ($canTest) {
                [$ok, $testMessage]          = testVersion($cwd);
                $testsMessages[$projectName] = $testMessage;
                if (!$ok) {
                    return;
                }
            }

            if (exists("$cwd/composer.lock")) {
                deleteFile("$cwd/composer.lock");
            }

            echo execute("git fetch", $cwd);
            echo execute("git pull", $cwd);
            // echo execute("git add .", $cwd);
            // echo execute("git commit -m\"$message\"", $cwd);
            echo execute("git push", $cwd);
            publishVersion(
                projectName: $projectName,
                library: $library,
                versionString: $versionString,
                cwd: $cwd,
                message: $message,
            );
        });

        $cache["projects"][$projectName]["version"] = $versionString;
    }

    awaitAll($pushInstructions);

    foreach ($testsMessages as $projectName => $testMessage) {
        if (!$testMessage) {
            continue;
        }
        echo "Test results for project $projectName.\n$testMessage";
    }

    $composerUpdateInstructions = [];
    $anyNuke                    = false;
    foreach ($projects as $projectName => $projectProperties) {
        $versionString = preg_replace('/"/', '\\"', $projectProperties['version']);
        $message       = preg_replace('/"/', '\\"', $projectProperties['message'] ?? "Version $versionString");
        echo "Updating project $projectName".PHP_EOL;
        $cwd                          = "$root/$projectName";
        $composerUpdateInstructions[] = async(fn () => execute("composer update", $cwd));
    }

    awaitAll($composerUpdateInstructions);
    
    $cacheStringified = yaml_emit($cache, YAML_UTF8_ENCODING);
    write(".sync.cache", $cacheStringified);
}


/**
 * @return void
 */
function nuke(string $cwd):void {
    #Delete local tags.
    echo execute("git tag -l | xargs git tag -d", $cwd);
    #Fetch remote tags.
    echo execute("git fetch", $cwd);
    #Delete remote tags.
    echo execute("git tag -l | xargs git push --delete origin", $cwd);
    #Delete local tags.
    echo execute("git tag -l | xargs git tag -d", $cwd);
}

function untag(string $cwd, string $name):void {
    #Delete local tags.
    execute("git tag -l | xargs git tag -d", $cwd);
    #Fetch remote tags.
    execute("git fetch", $cwd);
    #Delete remote tag.
    echo execute("git push --delete origin $name", $cwd);
    #Delete local tag.
    echo execute("git tag -d $name", $cwd);
}

function testVersion(string $cwd) {
    $red   = foreground(red: 220, green: 20, blue: 20);
    $green = foreground(red: 150, green: 220);

    $test = execute("composer run prod:test", $cwd);
    $ok   = $test->getCode() === 0;
    if ($ok) {
        $message = join([
            $green,
            $test,
            nocolor(),
        ]);
    } else {
        $message = join([
            $red,
            $test,
            nocolor(),
        ]);
    }
    
    return [$ok, $message];
}

function overwriteVersion(string $projectName, string $library, string $versionString, string $cwd, string $message) {
    $green = foreground(red: 150, green: 220);
    echo join([
        $green,
        "UNTAG: Tag of project $projectName ($library: $versionString) is being replaced.\n",
        nocolor(),
    ]);
    untag($cwd, $versionString);
    echo execute("git tag -a \"$versionString\" -m\"$message\"", $cwd);
    echo execute("git push --tags", $cwd);
}


function publishVersion(string $projectName, string $library, string $versionString, string $cwd, string $message) {
    $green = foreground(red: 150, green: 220);
    echo join([
        $green,
        "Project $projectName ($library) has a new version ($versionString).\n",
        nocolor(),
    ]);
    
    echo execute("git tag -a \"$versionString\" -m\"$message\"", $cwd);
    echo execute("git push --tags", $cwd);
}