<?php

define('MERGE_POLICY', 3);
define('PROGRAM_VERSION', 'Unknown Identity v.0.1.0');

define('DEFAULT_DATE_FORMAT', '%d.%m.%Y');
define('DEFAULT_RESPONSE', "\e[031mNo answer was found!\n");

define('RECURSION_LIMIT', 32);
define('RECURSION_ERROR_MESSAGE', "\e[031mRecursion limit was exceeded!\n");

$graph = [];

$categories = 0;
$recursion_counter = 0;

$substitutions = [];
$sets = [];
$maps = [];
$properties = [];
$vars = [];
$predicates = ['topic' => 'unknown'];

$starStack = [];
$tmpStack = [];

$stars = [];
$thatStars = [];
$topicStars = [];

$human = [];
$robot = [];

$that = 'unknown';

$id = getClientId();

init();

while (($userInput = fgets(STDIN)) !== false)
{
    $answer = getAnswer($userInput);

    if (empty($answer))
    {
        fwrite(STDERR, DEFAULT_RESPONSE);
        continue;
    }

    fwrite(STDOUT, "{$answer}\n");
}

function init()
{
    global $graph;

    /** @var RecursiveDirectoryIterator $it */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator('.')) as $it)
    {
        $path = $it->getPathname();

        switch ($it->getExtension())
        {
            case 'aiml':
                loadAiml($path);
                break;
            case 'set':
                loadSet($path);
                break;
            case 'map':
                loadMap($path);
                break;
            case 'property':
                loadProperty($path);
                break;
            case 'substitution':
                loadSubstitution($path);
                break;
        }
    }

    sortGraph($graph);
}

function loadAiml($path)
{
    global $categories;

    $options = LIBXML_PARSEHUGE | LIBXML_BIGLINES | LIBXML_COMPACT | LIBXML_NOBLANKS;
    $pattern = $that = $topic = $parentTopic = $template = null;
    $skip = false;

    $reader = new XMLReader();

    if (file_exists($path))
    {
        $reader->open($path, 'UTF-8', $options);
    }
    else
    {
        $reader->XML($path, 'UTF-8', $options);
    }

    while ($reader->read())
    {
        if ($skip)
        {
            $reader->next($skip);
            $skip = false;
        }
        else if ($reader->nodeType == XMLReader::ELEMENT)
        {
            if ($reader->name == 'topic' && ($name = $reader->getAttribute('name')) !== null)
            {
                $parentTopic = $name;
            }
            else if (in_array($reader->name, ['pattern', 'that', 'topic', 'template']))
            {
                ${$reader->name} = $reader->readOuterXML();
                $skip = $reader->name;
            }
        }
        else if ($reader->nodeType == XMLReader::END_ELEMENT)
        {
            if ($reader->name == 'category')
            {
                $categories++;

                addCategory($pattern, $that ?? '*', $topic ?? $parentTopic ?? '*', $template);
                $pattern = $that = $topic = $template = null;
            }
            else if ($reader->name == 'topic' && $reader->getAttribute('name') !== null)
            {
                $parentTopic = null;
            }
        }
    }

    $reader->close();
}

function loadSet($path)
{
    global $sets;

    $name = basename($path, '.set');
    $set = json_decode(file_get_contents($path));

    foreach ($set as [$value])
    {
        $sets[$name][] = mb_strtolower($value);
    }
}

function loadMap($path)
{
    global $maps;

    $name = basename($path, '.map');
    $map = json_decode(file_get_contents($path));

    foreach ($map as [$key, $value])
    {
        $maps[$name][mb_strtolower($key)] = $value;
    }
}

function loadProperty($path)
{
    global $properties;

    $property = json_decode(file_get_contents($path));

    foreach ($property as [$key, $value])
    {
        $properties[$key] = $value;
    }
}

function loadSubstitution($path)
{
    global $substitutions;

    $name = basename($path, '.substitution');
    $substitution = json_decode(file_get_contents($path));

    foreach ($substitution as [$search, $replace])
    {
        $substitutions[$name][$search] = $replace;
    }
}

function sortGraph(&$graph)
{
    if (!is_array($graph))
    {
        return;
    }

    foreach ($graph as &$subGraph)
    {
        sortGraph($subGraph);
    }

    uksort($graph, function ($a, $b) {
        return getPriority($b) <=> getPriority($a);
    });
}

function getPriority($word)
{
    switch ($word[0])
    {
        case '$':
            return 7;
        case '#':
            return 6;
        case '_':
            return 5;
        case '<':
            return 3;
        case '^':
            return 2;
        case '*':
            return 1;
        default:
            return 4;
    }
}

function addCategory($pattern, $that, $topic, $template)
{
    global $graph;

    $words = array_merge(
        ['<pattern>'],
        getWords(mb_strtolower($pattern)),
        ['<that>'],
        getWords(mb_strtolower($that)),
        ['<topic>'],
        getWords(mb_strtolower($topic)),
        ['<template>']
    );

    $subGraph = &$graph;

    foreach ($words as $word)
    {
        $subGraph = &$subGraph[$word];
    }

    $subGraph[] = $template;
}

function getWords($source)
{
    if (empty($source) || $source[0] !== '<')
    {
        return preg_split('/\s+/u', $source, -1, PREG_SPLIT_NO_EMPTY);
    }

    $words = [];
    $skip = false;

    $reader = new XMLReader();
    $reader->XML($source, 'UTF-8', LIBXML_COMPACT | LIBXML_NOBLANKS);

    $reader->read();

    while ($reader->read())
    {
        if ($skip)
        {
            $reader->next($skip);
            $skip = false;
        }
        else if ($reader->nodeType == XMLReader::TEXT)
        {
            $words = array_merge($words, preg_split('/\s+/u', $reader->value, -1, PREG_SPLIT_NO_EMPTY));
        }
        else if ($reader->isEmptyElement)
        {
            $words[] = $reader->readOuterXml();
        }
        else if ($reader->nodeType == XMLReader::ELEMENT)
        {
            $words[] = $reader->readOuterXml();
            $skip = $reader->name;
        }
    }

    return $words;
}

function search(&$graph, array $tail)
{
    global $sets, $properties;

    if (empty($tail))
    {
        return count($graph) == count($graph, COUNT_RECURSIVE);
    }

    $head = $tail[0];

    $found = false;
    $skipTag = false;
    $isTag = in_array($head, ['<pattern>', '<that>', '<topic>', '<template>']);

    foreach ($graph as $word => &$subGraph)
    {
        if ($found != false)
        {
            break;
        }
        else if ($word === '#' || $word === '^')
        {
            if ($isTag)
            {
                $found = search($subGraph, $tail);

                if ($found)
                {
                    addStars();
                    $skipTag = true;
                }
            }
            else
            {
                for ($i = 0; $found == false && $i <= count($tail); $i++)
                {
                    $found = search($graph[$word], array_slice($tail, $i));
                }

                if ($found)
                {
                    addStars(array_slice($tail, 0, $i - 1));
                }
            }
        }
        else if ($isTag == false && ($word === '_' || $word === '*'))
        {
            for ($i = 1; !$found && $i <= count($tail); $i++)
            {
                $found = search($graph[$word], array_slice($tail, $i));
            }

            if ($found)
            {
                addStars(array_slice($tail, 0, $i - 1));
            }
        }
        else if ($isTag == false && $word[0] === '<')
        {
            if (substr($word, 1, 3) == 'set')
            {
                $word = strip_tags($word);

                for ($i = 1; !$found && $i <= count($tail); $i++)
                {
                    $needle = implode(' ', array_slice($tail, 0, $i));

                    if (isset($sets[$word]) && in_array($needle, $sets[$word]))
                    {
                        $found = search($subGraph, array_slice($tail, $i));
                    }
                    else if ($word == 'number' && is_numeric($needle))
                    {
                        $found = search($subGraph, array_slice($tail, $i));
                    }
                }

                if ($found)
                {
                    addStars(array_slice($tail, 0, $i - 1));
                }
            }
            else if (substr($word, 1, 3) == 'bot')
            {
                $name = strip_tags($word);

                if (empty($name))
                {
                    $doc = new DOMDocument();
                    $doc->loadXML($word);
                    $name = $doc->childNodes->item(0)->getAttribute('name');
                }

                if (isset($properties[$name]) && strcasecmp($head, mb_strtolower($properties[$name])) === 0)
                {
                    $found = search($subGraph, array_slice($tail, 1));
                }
            }
        }
        else if (strcasecmp($word, $head) === 0 || $word[0] === '$' && strcasecmp(substr($word, 1), $head) === 0)
        {
            $found = search($subGraph, array_slice($tail, 1));
        }
    }

    if ($found && $isTag && $skipTag == false)
    {
        saveStars($head);

        if (count($tail) == 1)
        {
            return $graph[$head];
        }
    }

    return $found;
}

function getClientId()
{
    try
    {
        $id = bin2hex(random_bytes(16));
    }
    catch (Exception $exception)
    {
        exit($exception->getMessage());
    }

    return $id;
}

function replaceEvalElements(DOMElement $element)
{
    $evals = $element->getElementsByTagName('eval');

    while ($evals->length)
    {
        $text = new DOMText(parseTemplateRecursive($evals->item(0)));
        $evals->item(0)->parentNode->replaceChild($text, $evals->item(0));

        $evals = $element->getElementsByTagName('eval');
    }
}

function addStars($values = [])
{
    global $starStack;

    $starStack[] = empty($values) ? 'unknown' : implode(' ', $values);
}

function saveStars($tagName)
{
    global $starStack, $stars, $thatStars, $topicStars;

    $starStack = array_reverse($starStack);

    switch ($tagName)
    {
        case '<pattern>':
            $stars = $starStack;
            break;
        case '<that>':
            $thatStars = $starStack;
            break;
        case '<topic>':
            $topicStars = $starStack;
            break;
    }

    $starStack = [];
}

function saveTemps()
{
    global $stars, $thatStars, $topicStars, $vars, $tmpStack;

    $tmpStack[] = $stars;
    $stars = [];

    $tmpStack[] = $thatStars;
    $thatStars = [];

    $tmpStack[] = $topicStars;
    $topicStars = [];

    $tmpStack[] = $vars;
    $vars = [];
}

function restoreTemps()
{
    global $stars, $thatStars, $topicStars, $vars, $tmpStack;

    $vars = array_pop($tmpStack);
    $topicStars = array_pop($tmpStack);
    $thatStars = array_pop($tmpStack);
    $stars = array_pop($tmpStack);
}

function getAnswer($question)
{
    global $graph, $that, $predicates, $human, $robot;

    $answers = [];
    $sentences = getSentences($question);
    $human[] = $sentences;

    foreach ($sentences as $sentence)
    {
        $sentence = normalize($sentence);

        $words = array_merge(
            ['<pattern>'],
            getWords($sentence),
            ['<that>'],
            getWords($that),
            ['<topic>'],
            getWords($predicates['topic']),
            ['<template>']
        );

        $templates = search($graph, $words);

        if (!is_array($templates))
        {
            continue;
        }

        $answer = parseTemplate($templates);
        $answers[] = $answer;

        if (empty($answer))
        {
            continue;
        }

        $robotSentences = getSentences($answer);
        $robot[] = $robotSentences;

        $that = normalize($robotSentences[count($robotSentences) - 1]);
    }

    return preg_replace('/^\s+/um', '', implode(' ', $answers));
}

function getSentences($question)
{
    return preg_split('/(?<=[.?!])\s+(?=\pL)/u', $question, -1, PREG_SPLIT_NO_EMPTY);
}

function normalize($sentence)
{
    global $substitutions;

    $sentence = strip_tags(mb_strtolower($sentence));
    $sentence = str_replace(array_keys($substitutions['normalize']), $substitutions['normalize'], $sentence);

    return preg_replace(['/[^\pL\d\s]+/u', '/(\s){2,}/u'], ['', '$1'], trim($sentence));
}

function parseTemplate(array $templates)
{
    $template = null;

    if (MERGE_POLICY == 1 || count($templates) == 1)
    {
        $template = $templates[0];
    }
    else if (MERGE_POLICY == 2)
    {
        $template = $templates[count($templates) - 1];
    }
    else
    {
        $doc = new DOMDocument();

        $template = $doc->createElement('template');
        $random = $doc->createElement('random');

        foreach ($templates as $value)
        {
            $li = $doc->createElement('li', htmlentities($value));
            $random->appendChild($li);
        }

        $template->appendChild($random);
        $doc->appendChild($template);

        $template = html_entity_decode($doc->saveHTML());
    }

    $element = new DOMDocument();
    $element->loadXML($template);

    return parseTemplateRecursive($element->getElementsByTagName('template')->item(0));
}

function parseTemplateRecursive(DOMElement $element)
{
    $output = [];

    $class = ucfirst($element->nodeName);

    if (method_exists($class, 'before'))
    {
        $element = $class::before($element);
    }

    /** @var DOMElement $node */
    foreach ($element->childNodes as $node)
    {
        if ($node->nodeType == XML_ELEMENT_NODE)
        {
            $output[] = parseTemplateRecursive($node);
        }
        else if ($node->nodeType == XML_TEXT_NODE)
        {
            $output[] = $node->nodeValue;
        }
    }

    $output = implode($output);

    if (method_exists($class, 'after'))
    {
        $output = $class::after($output);
    }

    return $output;
}

abstract class Tag
{
    public static function getImmediateElementByTagName($tagName, DOMElement $element)
    {
        foreach ($element->childNodes as $child)
        {
            if ($child instanceof DOMElement && $child->tagName == $tagName)
            {
                return $child;
            }
        }

        return null;
    }

    public static function getAttribute($name, DOMElement $element)
    {
        $attr = $element->getAttribute($name);

        if (empty($attr))
        {
            $tag = self::getImmediateElementByTagName($name, $element);

            if ($tag === null)
            {
                return null;
            }

            $attr = parseTemplateRecursive($tag);

            $element->setAttribute($name, $attr);
            $element->removeChild($tag);
        }

        return $attr;
    }

    public static function before(DOMElement $element): DOMElement
    {
        return $element;
    }

    public static function after($output)
    {
        return $output;
    }
}

class Random extends Tag
{
    public static function before(DOMElement $element): DOMElement
    {
        $li = $element->getElementsByTagName('li');
        $index = rand(0, $li->length - 1);

        return $li->length <= 0 ? $element : $li->item($index);
    }
}

class Lowercase extends Tag
{
    public static function after($output)
    {
        return mb_strtolower($output);
    }
}

class Uppercase extends Tag
{
    public static function after($output)
    {
        return mb_strtoupper($output);
    }
}

class Formal extends Tag
{
    public static function after($output)
    {
        return mb_convert_case($output, MB_CASE_TITLE);
    }
}

class Bot extends Tag
{
    private static $name;

    public static function before(DOMElement $element): DOMElement
    {
        self::$name = self::getAttribute('name', $element);
        return $element;
    }

    public static function after($output)
    {
        global $properties;
        return $properties[self::$name] ?? 'undefined';
    }
}

class Rest extends Tag
{
    public static function after($output)
    {
        return preg_replace('/^[^\s]*\s/m', '', $output);
    }
}

class First extends Tag
{
    public static function after($output)
    {
        return preg_replace('/\s.*$/m', '', $output);
    }
}

class Program extends Tag
{
    public static function after($output)
    {
        return PROGRAM_VERSION;
    }
}

class Think extends Tag
{
    public static function after($output)
    {
        return '';
    }
}

class Explode extends Tag
{
    public static function after($output)
    {
        return implode(' ', preg_split('//u', $output, -1, PREG_SPLIT_NO_EMPTY));
    }
}

class Set extends Tag
{
    private static $var;

    private static $name;

    public static function before(DOMElement $element): DOMElement
    {
        self::$var = self::getAttribute('var', $element);
        self::$name = self::getAttribute('name', $element);

        return $element;
    }

    public static function after($output)
    {
        global $predicates, $vars;

        if (empty(self::$name))
        {
            $vars[self::$var] = $output;
        }
        else
        {
            $predicates[self::$name] = $output;
        }

        return $output;
    }
}

class Get extends Tag
{
    private static $var;

    private static $name;

    public static function before(DOMElement $element): DOMElement
    {
        self::$var = self::getAttribute('var', $element);
        self::$name = self::getAttribute('name', $element);

        return $element;
    }

    public static function after($output)
    {
        global $predicates, $vars;

        return empty(self::$name) ? ($vars[self::$var] ?? 'undefined') : ($predicates[self::$name] ?? 'undefined');
    }
}

class Template extends Tag
{
    public static function before(DOMElement $element): DOMElement
    {
        global $vars;

        $vars = [];
        return $element;
    }
}

class Star extends Tag
{
    private static $index;

    public static function before(DOMElement $element): DOMElement
    {
        self::$index = self::getAttribute('index', $element);
        return $element;
    }

    public static function after($output)
    {
        global $stars;

        if (empty(self::$index))
        {
            self::$index = 1;
        }

        return $stars[self::$index - 1] ?? 'undefined';
    }
}

class Topicstar extends Tag
{
    private static $index;

    public static function before(DOMElement $element): DOMElement
    {
        self::$index = self::getAttribute('index', $element);
        return $element;
    }

    public static function after($output)
    {
        global $topicStars;

        if (empty(self::$index))
        {
            self::$index = 1;
        }

        return $topicStars[self::$index - 1] ?? 'undefined';
    }
}

class Thatstar extends Tag
{
    private static $index;

    public static function before(DOMElement $element): DOMElement
    {
        self::$index = self::getAttribute('index', $element);
        return $element;
    }

    public static function after($output)
    {
        global $thatStars;

        if (empty(self::$index))
        {
            self::$index = 1;
        }

        return $thatStars[self::$index - 1] ?? 'undefined';
    }
}

class Map extends Tag
{
    private static $name;

    public static function before(DOMElement $element): DOMElement
    {
        self::$name = self::getAttribute('name', $element);
        return $element;
    }

    public static function after($output)
    {
        global $maps;

        $output = trim($output);

        if (self::$name == 'successor')
        {
            if (!is_numeric($output))
            {
                return 'unknown';
            }

            $output = bcadd($output, '1');
        }
        else if (self::$name == 'predecessor')
        {
            if (!is_numeric($output))
            {
                return 'unknown';
            }

            $output = bcsub($output, '1');
        }
        else
        {
            $output = $maps[self::$name][$output] ?? 'undefined';
        }

        return $output;
    }
}

class Condition extends Tag
{
    private static $var;

    private static $name;

    private static $value;

    public static function before(DOMElement $element): DOMElement
    {
        global $predicates, $vars;

        self::$var = self::getAttribute('var', $element);
        self::$name = self::getAttribute('name', $element);
        self::$value = self::getAttribute('value', $element);

        if (!empty(self::$var) && !empty(self::$value))
        {
            if (isset($predicates[self::$name]) && $vars[self::$var] === self::$value)
            {
                return $element;
            }
            else if (!isset($vars[self::$var]) && self::$value == 'undefined')
            {
                return $element;
            }
        }
        else if (!empty(self::$name) && !empty(self::$value))
        {
            if (isset($predicates[self::$name]) && $predicates[self::$name] === self::$value)
            {
                return $element;
            }
            else if (!isset($predicates[self::$name]) && self::$value == 'undefined')
            {
                return $element;
            }
        }
        else if ((!empty(self::$var) || !empty(self::$name)))
        {
            $value = empty(self::$var) ? ($predicates[self::$name] ?? 'undefined') : ($vars[self::$var] ?? 'undefined');

            /** @var DOMElement $li */
            foreach ($element->childNodes as $li)
            {
                if ($li->nodeType != XML_ELEMENT_NODE)
                {
                    continue;
                }

                $attrValue = self::getAttribute('value', $li);

                if ($attrValue === null || $attrValue === $value)
                {
                    return $li;
                }
            }
        }
        else if (empty(self::$var) && empty(self::$name) && empty(self::$value))
        {
            /** @var DOMElement $li */
            foreach ($element->childNodes as $li)
            {
                if ($li->nodeType !== XML_ELEMENT_NODE)
                {
                    continue;
                }

                $var = self::getAttribute('var', $li);
                $name = self::getAttribute('name', $li);
                $value = self::getAttribute('value', $li);

                if (($name === null && $var === null && $value === null))
                {
                    return $li;
                }
                else if ($var === null && $value == ($predicates[$name] ?? 'undefined') ||
                    $name === null && $value == ($vars[$var] ?? 'undefined'))
                {
                    return $li;
                }
            }
        }

        $element->nodeValue = '';
        return $element;
    }
}

class Loop extends Tag
{
    public static function before(DOMElement $element): DOMElement
    {
        $element->parentNode->appendChild(clone $element->parentNode->parentNode);
        return $element;
    }
}

class Date extends Tag
{
    private static $format;

    private static $jformat;

    public static function before(DOMElement $element): DOMElement
    {
        self::$format = self::getAttribute('format', $element);
        self::$jformat = self::getAttribute('jformat', $element);

        return $element;
    }

    public static function after($output)
    {
        return strftime(self::$format ?? DEFAULT_DATE_FORMAT);
    }
}

class Denormalize extends Tag
{
    public static function after($output)
    {
        global $substitutions;
        return str_replace(array_keys($substitutions['denormalize']), $substitutions['denormalize'], " {$output} ");
    }
}

class Normalize extends Tag
{
    public static function after($output)
    {
        global $substitutions;
        return trim(str_replace(array_keys($substitutions['normalize']), $substitutions['normalize'], " {$output} "));
    }
}

class Gender extends Tag
{
    private static $index;

    public static function before(DOMElement $element): DOMElement
    {
        self::$index = self::getAttribute('index', $element);
        return $element;
    }

    public static function after($output)
    {
        global $stars, $substitutions;

        if (empty($output))
        {
            $output = $stars[max(0, self::$index - 1)] ?? '';
        }

        return trim(str_replace(array_keys($substitutions['gender']), $substitutions['gender'], " {$output} "));
    }
}

class Person extends Tag
{
    private static $index;

    public static function before(DOMElement $element): DOMElement
    {
        self::$index = self::getAttribute('index', $element);
        return $element;
    }

    public static function after($output)
    {
        global $stars, $substitutions;

        if (empty($output))
        {
            $output = $stars[max(0, self::$index - 1)] ?? '';
        }

        return trim(str_replace(array_keys($substitutions['person']), $substitutions['person'], " {$output} "));
    }
}

class Person2 extends Tag
{
    private static $index;

    public static function before(DOMElement $element): DOMElement
    {
        self::$index = self::getAttribute('index', $element);
        return $element;
    }

    public static function after($output)
    {
        global $stars, $substitutions;

        if (empty($output))
        {
            $output = $stars[max(0, self::$index - 1)] ?? '';
        }

        return trim(str_replace(array_keys($substitutions['person2']), $substitutions['person2'], " {$output} "));
    }
}

class Id extends Tag
{
    public static function after($output)
    {
        global $id;
        return $id;
    }
}

class Interval extends Tag
{
    private static $format;

    private static $style;

    private static $from;

    private static $to;

    public static function before(DOMElement $element): DOMElement
    {
        self::$format = self::getAttribute('format', $element);
        self::$style = self::getAttribute('style', $element);
        self::$from = self::getAttribute('from', $element);
        self::$to = self::getAttribute('to', $element);

        return $element;
    }

    public static function after($output)
    {
        $from = new DateTime(self::$from);
        $to = new DateTime(self::$to);

        switch (self::$style)
        {
            case 'years':
                return $from->diff($to)->y;
            case 'months':
                return $from->diff($to)->m;
            case 'days':
                return $from->diff($to)->days;
            case 'minutes':
                return $from->diff($to)->i;
            case 'seconds':
                return $from->diff($to)->s;
        }

        return $output;
    }
}

class Learn extends Tag
{
    public static function before(DOMElement $element): DOMElement
    {
        replaceEvalElements($element);

        loadAiml($element->ownerDocument->saveXML($element));
        $element->nodeValue = '';

        return $element;
    }
}

class Learnf extends Tag
{
    public static function before(DOMElement $element): DOMElement
    {
        replaceEvalElements($element);

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $aiml = $doc->createElement('aiml');
        $aiml->setAttribute('version', '2.0');

        /** @var DOMNode $category */
        foreach ($element->childNodes as $category)
        {
            if ($category->nodeType !== XML_ELEMENT_NODE)
            {
                continue;
            }

            $category = $doc->importNode($category, true);
            $aiml->appendChild($category);
        }

        $doc->appendChild($aiml);
        $source = $element->ownerDocument->saveXML($element);
        loadAiml($source);

        file_put_contents('resources/aiml/learnf.aiml', $doc->saveXML());
        
        $element->nodeValue = '';
        return $element;
    }
}

class Sentence extends Tag
{
    public static function after($output)
    {
        return empty($output) ?: mb_strtoupper($output[0]) . mb_substr($output, 1);
    }
}

class Sr extends Tag
{
    public static function after($output)
    {
        global $stars, $recursion_counter;

        if ($recursion_counter++ >= RECURSION_LIMIT)
        {
            return RECURSION_ERROR_MESSAGE;
        }

        $star = $stars[0] ?? 'undefined';

        saveTemps();
        $answer = getAnswer($star);
        restoreTemps();

        return $answer;
    }
}

class Srai extends Tag
{
    public static function after($output)
    {
        global $recursion_counter;

        if ($recursion_counter++ >= RECURSION_LIMIT)
        {
            return RECURSION_ERROR_MESSAGE;
        }

        saveTemps();
        $answer = getAnswer($output);
        restoreTemps();

        return $answer;
    }
}

class Size extends Tag
{
    public static function after($output)
    {
        global $categories;
        return $categories;
    }
}

class Request extends Tag
{
    private static $index;

    public static function before(DOMElement $element): DOMElement
    {
        self::$index = self::getAttribute('index', $element);
        return $element;
    }

    public static function after($output)
    {
        global $human;

        $request = array_slice($human, -self::$index, 1);

        if (empty($request) || !is_array($request))
        {
            return $output;
        }

        return implode(' ', $request[0]);
    }
}

class Response extends Tag
{
    private static $index;

    public static function before(DOMElement $element): DOMElement
    {
        self::$index = self::getAttribute('index', $element);
        return $element;
    }

    public static function after($output)
    {
        global $robot;

        $response = array_slice($robot, -self::$index, 1);

        if (empty($response) || !is_array($response))
        {
            return $output;
        }

        return implode(' ', $response[0]);
    }
}

class Input extends Tag
{
    private static $index;

    public static function before(DOMElement $element): DOMElement
    {
        self::$index = self::getAttribute('index', $element);
        return $element;
    }

    public static function after($output)
    {
        global $human;

        $index = self::$index;
        $last = [];

        while ($index > 0 && !empty($human))
        {
            $last = array_pop($human);
            $index -= count($last);
        }

        return array_slice($last, abs($index), 1)[0] ?? $output;
    }
}

class That extends Tag
{
    private static $index;

    public static function before(DOMElement $element): DOMElement
    {
        self::$index = self::getAttribute('index', $element);
        return $element;
    }

    public static function after($output)
    {
        global $robot;

        $index = explode(',', self::$index);

        $index1 = $index[0] ?? 1;
        $index2 = $index[1] ?? 1;

        $that = array_slice($robot, -$index1, 1);

        if (empty($that) || !is_array($that))
        {
            return $output;
        }

        return array_slice($that[0], -$index2, 1)[0] ?? [$output];
    }
}
