<?php

declare(strict_types=1);

chdir(dirname(__DIR__));

require_once __DIR__ . '/../Tests/bootstrap.php';

class LegacyTestFailure extends Exception {
}

if (!class_exists('PHPUnit_Framework_TestCase', false)) {
    class PHPUnit_Framework_TestCase {
        public static $assertionCount = 0;

        protected function setUp() {
        }

        protected function tearDown() {
        }

        protected function fail($message) {
            throw new LegacyTestFailure($message);
        }

        protected function assertEquals($expected, $actual, $message = '') {
            self::$assertionCount++;
            if ($expected != $actual) {
                $this->fail($this->formatFailureMessage($message, $expected, $actual));
            }
        }

        protected function assertTrue($condition, $message = '') {
            self::$assertionCount++;
            if ($condition !== true) {
                $this->fail($this->formatFailureMessage($message, true, $condition));
            }
        }

        protected function assertFalse($condition, $message = '') {
            self::$assertionCount++;
            if ($condition !== false) {
                $this->fail($this->formatFailureMessage($message, false, $condition));
            }
        }

        private function formatFailureMessage($message, $expected, $actual) {
            $baseMessage = $message !== '' ? $message . "\n" : '';
            return $baseMessage
                . 'Expected: ' . $this->exportValue($expected) . "\n"
                . 'Actual:   ' . $this->exportValue($actual);
        }

        private function exportValue($value) {
            if (is_string($value)) {
                return "'" . $value . "'";
            }

            return trim(var_export($value, true));
        }
    }
}

function discoverTestFiles($rootPath) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootPath, FilesystemIterator::SKIP_DOTS)
    );
    $files = array();

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }
        if (substr($fileInfo->getFilename(), -8) !== 'Test.php') {
            continue;
        }
        $files[] = $fileInfo->getPathname();
    }

    sort($files);

    return $files;
}

function getOrderedTestMethods(ReflectionClass $class) {
    $source = file_get_contents($class->getFileName());
    preg_match_all('/function\s+(test[A-Za-z0-9_]+)/', $source, $matches);

    $orderedMethods = array();
    foreach ($matches[1] as $methodName) {
        if ($class->hasMethod($methodName)) {
            $orderedMethods[] = $methodName;
        }
    }

    return array_values(array_unique($orderedMethods));
}

function invokeLifecycleMethod($object, $methodName) {
    if (!method_exists($object, $methodName)) {
        return;
    }

    $method = new ReflectionMethod($object, $methodName);
    $method->setAccessible(true);
    $method->invoke($object);
}

function getExpectedException(ReflectionMethod $method) {
    $docComment = $method->getDocComment();
    if ($docComment && preg_match('/@expectedException\s+([^\s]+)/', $docComment, $matches)) {
        return $matches[1];
    }

    return null;
}

function renderThrowable(Throwable $throwable) {
    return get_class($throwable) . ': ' . $throwable->getMessage();
}

$failures = array();
$testCount = 0;
$startNs = hrtime(true);

foreach (discoverTestFiles(__DIR__ . '/../Tests/Amfphp') as $testFile) {
    $beforeClasses = get_declared_classes();
    require_once $testFile;
    $afterClasses = get_declared_classes();
    $newClasses = array_values(array_diff($afterClasses, $beforeClasses));

    foreach ($newClasses as $className) {
        if (!is_subclass_of($className, 'PHPUnit_Framework_TestCase')) {
            continue;
        }

        $class = new ReflectionClass($className);
        foreach (getOrderedTestMethods($class) as $methodName) {
            $testCount++;
            $test = $class->newInstance();
            $method = $class->getMethod($methodName);
            $expectedException = getExpectedException($method);
            $thrown = null;
            $setUpSucceeded = false;

            try {
                invokeLifecycleMethod($test, 'setUp');
                $setUpSucceeded = true;
                $method->invoke($test);
            } catch (Throwable $throwable) {
                $thrown = $throwable;
            }

            if ($setUpSucceeded) {
                try {
                    invokeLifecycleMethod($test, 'tearDown');
                } catch (Throwable $tearDownThrowable) {
                    if ($thrown === null) {
                        $thrown = $tearDownThrowable;
                    }
                }
            }

            if ($expectedException !== null) {
                if ($thrown === null) {
                    $failures[] = $className . '::' . $methodName
                        . ' expected exception ' . $expectedException . ' but nothing was thrown';
                    continue;
                }

                if (!is_a($thrown, $expectedException)) {
                    $failures[] = $className . '::' . $methodName
                        . ' expected exception ' . $expectedException
                        . ' but got ' . renderThrowable($thrown);
                }
                continue;
            }

            if ($thrown !== null) {
                $failures[] = $className . '::' . $methodName . ' failed with ' . renderThrowable($thrown);
            }
        }
    }
}

$elapsedMs = round((hrtime(true) - $startNs) / 1000000, 3);

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: $failure\n");
    }

    fwrite(
        STDERR,
        sprintf(
            "FAILED: %d tests, %d assertions, %d failures in %.3f ms\n",
            $testCount,
            PHPUnit_Framework_TestCase::$assertionCount,
            count($failures),
            $elapsedMs
        )
    );
    exit(1);
}

fwrite(
    STDOUT,
    sprintf(
        "OK: %d tests, %d assertions in %.3f ms\n",
        $testCount,
        PHPUnit_Framework_TestCase::$assertionCount,
        $elapsedMs
    )
);

