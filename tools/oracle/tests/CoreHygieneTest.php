<?php

declare(strict_types=1);

namespace Kreiswolke\FormAntispam\Oracle\Tests;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Static guarantees about the shipped core: PHP 7.4 syntax, no WordPress, no I/O.
 *
 * The dev box only has PHP 8.2, so `php -l` proves nothing about the 7.4 floor.
 * These tests parse each file with nikic/php-parser pinned to PHP 7.4 and then walk
 * the AST for the constructs the 7.4 grammar happens to tolerate but the 7.4 runtime
 * does not support.
 */
#[Group('hygiene')]
final class CoreHygieneTest extends TestCase
{
    private const CORE_DIR = __DIR__ . '/../../../plugin/includes/altcha';

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function coreFiles(): iterable
    {
        $files = glob(self::CORE_DIR . '/*.php');
        if (!is_array($files) || [] === $files) {
            throw new \RuntimeException('No core files found in ' . self::CORE_DIR);
        }

        foreach ($files as $file) {
            yield basename($file) => [$file];
        }
    }

    /**
     * Source with comments, doc comments and string literals removed, so lexical
     * checks cannot be fooled by prose.
     */
    private function codeOnly(string $file): string
    {
        $skip = [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML];
        $out  = '';

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], $skip, true)) {
                    $out .= ' ';
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    /**
     * @return Node[]
     */
    private function parse74(string $file): array
    {
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromString('7.4'));
        $ast    = $parser->parse((string) file_get_contents($file));

        self::assertIsArray($ast);

        return $ast;
    }

    #[TestDox('Parses under a PHP 7.4 grammar (catches enum, match, readonly)')]
    #[DataProvider('coreFiles')]
    public function testParsesAsPhp74(string $file): void
    {
        $this->parse74($file);
        self::assertTrue(true);
    }

    #[TestDox('Contains no PHP 8 only constructs the 7.4 grammar happens to tolerate')]
    #[DataProvider('coreFiles')]
    public function testNoPhp8OnlyConstructs(string $file): void
    {
        $ast    = $this->parse74($file);
        $finder = new NodeFinder();

        foreach ($finder->findInstanceOf($ast, Node\Param::class) as $param) {
            self::assertSame(0, $param->flags, 'Constructor property promotion (PHP 8.0) in ' . basename($file));
            self::assertNotInstanceOf(Node\Expr\New_::class, $param->default, '"new" in initializer (PHP 8.1) in ' . basename($file));
        }

        self::assertCount(0, $finder->findInstanceOf($ast, Node\Expr\NullsafePropertyFetch::class), 'Nullsafe operator (PHP 8.0)');
        self::assertCount(0, $finder->findInstanceOf($ast, Node\Expr\NullsafeMethodCall::class), 'Nullsafe operator (PHP 8.0)');
        self::assertCount(0, $finder->findInstanceOf($ast, Node\UnionType::class), 'Union type (PHP 8.0)');
        self::assertCount(0, $finder->findInstanceOf($ast, Node\IntersectionType::class), 'Intersection type (PHP 8.1)');
        self::assertCount(0, $finder->findInstanceOf($ast, Node\AttributeGroup::class), 'Attribute (PHP 8.0)');
        self::assertCount(0, $finder->findInstanceOf($ast, Node\VariadicPlaceholder::class), 'First-class callable syntax (PHP 8.1)');

        foreach ($finder->findInstanceOf($ast, Node\Arg::class) as $arg) {
            self::assertNull($arg->name, 'Named argument (PHP 8.0) in ' . basename($file));
        }

        foreach ($finder->findInstanceOf($ast, Node\Stmt\Catch_::class) as $catch) {
            self::assertNotNull($catch->var, 'Non-capturing catch (PHP 8.0) in ' . basename($file));
        }

        // Return / parameter / property types that do not exist on 7.4.
        $forbiddenTypes = ['mixed', 'never', 'static', 'false', 'null', 'true'];
        foreach ($finder->findInstanceOf($ast, Node\Identifier::class) as $identifier) {
            self::assertNotContains(
                strtolower($identifier->name),
                $forbiddenTypes,
                'Type "' . $identifier->name . '" is not available on PHP 7.4 (' . basename($file) . ')',
            );
        }
    }

    #[TestDox('Contains no trailing comma in a parameter list (a PHP 7.4 parse error)')]
    #[DataProvider('coreFiles')]
    public function testNoTrailingCommaInParameterLists(string $file): void
    {
        $code = $this->codeOnly($file);

        // `function name(` / `function (` / `fn(` followed by a parameter list that
        // ends in a comma. Nested parentheses cannot occur in a parameter list except
        // inside default values, which this core does not use.
        self::assertSame(
            0,
            preg_match('/\b(?:function|fn)\s*&?\s*[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*\s*\([^()]*,\s*\)/', $code)
                + preg_match('/\b(?:function|fn)\s*&?\s*\([^()]*,\s*\)/', $code),
            'Trailing comma in a parameter list in ' . basename($file),
        );
    }

    #[TestDox('Calls no function that postdates PHP 7.4')]
    #[DataProvider('coreFiles')]
    public function testNoPostPhp74Functions(string $file): void
    {
        $ast    = $this->parse74($file);
        $finder = new NodeFinder();
        $source = (string) file_get_contents($file);

        $forbidden = [
            'str_contains', 'str_starts_with', 'str_ends_with',
            'json_validate', 'array_find', 'array_any', 'array_all',
            'mb_str_pad', 'enum_exists',
        ];

        foreach ($finder->findInstanceOf($ast, Node\Expr\FuncCall::class) as $call) {
            if (!$call->name instanceof Node\Name) {
                continue;
            }
            $name = strtolower($call->name->toString());

            self::assertNotContains($name, $forbidden, 'Function ' . $name . '() postdates PHP 7.4 (' . basename($file) . ')');

            if ('array_is_list' === $name) {
                self::assertStringContainsString(
                    "function_exists( 'array_is_list' )",
                    $source,
                    'array_is_list() is PHP 8.1 and must stay behind a function_exists() guard.',
                );
            }
        }
    }

    #[TestDox('Has zero WordPress dependencies')]
    #[DataProvider('coreFiles')]
    public function testNoWordPressDependencies(string $file): void
    {
        $code = $this->codeOnly($file);

        $patterns = [
            'WordPress function prefix' => '/\bwp_[a-z0-9_]*\s*\(/i',
            'WPINC constant'            => '/\bWPINC\b/',
            'ABSPATH constant'          => '/\bABSPATH\b/',
            'hook registration'         => '/\b(add_action|add_filter|do_action|apply_filters|register_activation_hook)\s*\(/i',
            'options API'               => '/\b(get_option|update_option|add_option|delete_option|get_transient|set_transient)\s*\(/i',
            'escaping helpers'          => '/\b(esc_html|esc_attr|esc_url|sanitize_text_field|__|_e|_x)\s*\(/',
            'global $wpdb'              => '/\$wpdb\b/',
        ];

        foreach ($patterns as $label => $pattern) {
            self::assertSame(0, preg_match($pattern, $code), $label . ' found in ' . basename($file));
        }
    }

    #[TestDox('Opens no socket and performs no I/O')]
    #[DataProvider('coreFiles')]
    public function testNoNetworkOrProcessCalls(string $file): void
    {
        $code = $this->codeOnly($file);

        $forbidden = [
            'curl_init', 'curl_exec', 'curl_setopt', 'fsockopen', 'pfsockopen',
            'stream_socket_client', 'stream_context_create', 'socket_create',
            'file_get_contents', 'file_put_contents', 'fopen', 'readfile',
            'get_headers', 'dns_get_record', 'gethostbyname', 'checkdnsrr',
            'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen',
            'eval', 'assert', 'create_function', 'unserialize', 'extract',
            'wp_remote_get', 'wp_remote_post', 'wp_safe_remote_get',
        ];

        foreach ($forbidden as $fn) {
            self::assertSame(
                0,
                preg_match('/(?<![\w\$>])' . preg_quote($fn, '/') . '\s*\(/i', $code),
                $fn . '() must not appear in the shipped ALTCHA core (' . basename($file) . ')',
            );
        }

        // `require_once` of a sibling file is the only file-system touch we allow, and
        // only in the optional loader.
        if ('autoload.php' !== basename($file)) {
            self::assertSame(0, preg_match('/\b(require|include)(_once)?\b/', $code), 'No includes in ' . basename($file));
        }
    }

    #[TestDox('Uses only cryptographically secure randomness')]
    #[DataProvider('coreFiles')]
    public function testNoWeakRandomness(string $file): void
    {
        $code = $this->codeOnly($file);

        foreach (['rand', 'mt_rand', 'srand', 'mt_srand', 'uniqid', 'array_rand', 'shuffle', 'str_shuffle', 'lcg_value'] as $fn) {
            self::assertSame(
                0,
                preg_match('/(?<![\w\$>_])' . preg_quote($fn, '/') . '\s*\(/i', $code),
                $fn . '() is not a cryptographic RNG (' . basename($file) . ')',
            );
        }
    }

    #[TestDox('Compares secrets only with hash_equals()')]
    public function testSecretComparisonsUseHashEquals(): void
    {
        $verifier = $this->codeOnly(self::CORE_DIR . '/class-verifier.php');

        self::assertGreaterThanOrEqual(
            3,
            substr_count($verifier, 'hash_equals('),
            'Expected the signature, derived-key and keyPrefix comparisons to use hash_equals().',
        );
    }

    #[TestDox('Declares the expected namespace and nothing else')]
    #[DataProvider('coreFiles')]
    public function testNamespace(string $file): void
    {
        if ('autoload.php' === basename($file)) {
            self::assertStringNotContainsString('namespace ', $this->codeOnly($file));

            return;
        }

        self::assertStringContainsString('namespace Kreiswolke\FormAntispam\Altcha;', $this->codeOnly($file));
    }
}
