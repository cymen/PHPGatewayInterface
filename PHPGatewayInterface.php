<?php
/**
 * PHPGatewayInterface
 *
 * This class provides a PHP frontend to CGI or Common Gateway Interface
 * scripts. It was written for CVSWeb and tested with CVSWeb so the
 * environemental variables sent to the CGI environment (see setEnv) may need
 * adjustment for other CGIs.
 *
 * Known bugs:
 *  - Annotated view of binary files ends at binary content. Appears to be an
 *      issue with the DOMDocument component of PHP.
 *
 * Known potential issues:
 *  - PHP safe mode can't be used as putenv() conflicts with it.
 *
 * Copyright 2009 Cymen Vig
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
class PHPGatewayInterface
{

    /**
     * Options for CGI environment and script details.
     */
    private $options;

    /**
     * DOMDocument of CGI output if reply is of type text/html.
     */
    private $doc;

    /**
     * CGI script details.
     */
    private $cgi = array
    (
        'env'       => null,
        'script'    => null,
    );

    /**
     * Values returned by execution of CGI script.
     */
    private $result = array
    (
        'content-type'  => 'text/html',
        'header'        => null,
        'body'          => null,
    );


    /**
     * Construct PHPGatewayInterface which runs the CGI script with
     * supplied values.
     *
     * @param array options including script environment
     */
    public function __construct($options = array())
    {
        // setup
        $this->setOptions($options);
        $this->cgi['script'] = $options['SCRIPT_FILENAME'];
        $this->setEnv();

        // run CGI
        $this->parseResponse($this->execute());
    }


    /**
     * Run the CGI script.
     *
     * @return string output of CGI script.
     */
    private function execute()
    {
        if (! file_exists($this->cgi['script']))
        {
            throw new Exception("CGI script '". $this->cgi['script'] ."' not found!");
        }

        return shell_exec($this->cgi['script']);
    }


    /**
     * Parse the CGI poutput to header and body. If text/html, use DOMDocument
     * to rewrite response.
     *
     * @param string output of CGI
     */
    private function parseResponse($response = null)
    {
        if (empty($response))
        {
            $this->result['header'] = '';
            $this->result['body'] = '';
            return;
        }

        $data = explode("\n", $response);
        unset($response);

        // "parse" header
        $tmp = array();
        while (strlen(trim($line = array_shift($data))))
        {
            $tmp[] = $line;
        }
        $this->result['header'] = join("\n", $tmp);

        // set content type
        if (eregi('content-type:[ ]?([a-z0-9-]*/[a-z0-9-]*)', $this->result['header'], $matches))
        {
            $this->result['content-type'] = $matches[1];
        }

        // parse body
        $body = implode("\n", $data);
        if (isset($this->options['FILTER']))
        {
            $filter = unserialize($this->options['FILTER']);
            $body = preg_replace($filter['pattern'], $filter['replacement'], $body);
        }
        unset($data);

        if ($this->result['content-type'] == 'text/html')
        {
            $doc = new DOMDocument();
            @$doc->loadHTML($body);
           
            // rewrite A hrefs
            foreach ($doc->getElementsByTagName('a') as $a)
            {
                $href = $a->getAttribute('href');
                if (eregi('^(mailto|http)', $href) or strlen($href) == 0) continue;
               
                $a->setAttribute('href', $this->makeURL($href));
            }

            // rewrite FORM action and add hidden field for proper proxying
            foreach ($doc->getElementsByTagName('form') as $f)
            {
                $action = $f->getAttribute('action');
                $action_new = $this->makeURL($action);
                $f->setAttribute('action', $action_new);
            
                // add path info to form as hidden field
                $input = $doc->createElement('input');
                $input->setAttribute('name', 'href');
                $input->setAttribute('value', $this->options['PATH_INFO']);
                $input->setAttribute('type', 'hidden');
                $f->appendChild($doc->importNode($input, true));
                if (isset($this->options['GET']))
                {
                    // add GET options to any form so they persist
                    $key_values = explode('&', $this->options['GET']);
                    foreach ($key_values as $kv)
                    {
                        list ($key, $value) = split('=', $kv);
                        $input = $doc->createElement('input');
                        $input->setAttribute('type', 'hidden');
                        $input->setAttribute('name', $key);
                        $input->setAttribute('value', $value);
                        $f->appendChild($doc->importNode($input, true));
                    }
                }
            }
           
            // rewrite IMG src (but only those with relative path)
            foreach ($doc->getElementsByTagName('img') as $i)
            {
                $src = $i->getAttribute('src');
                if (! self::beginsWith('/', $src))
                {
                    $i->setAttribute('src', $this->makeURL($src) .'&wrapper_embedded=true');
                }
            }

            $this->doc = $doc;
            $this->result['body'] = $doc->saveHTML();
        }
        else
        {
            if (strpos($this->options['REQUEST_URI'], 'wrapper_embedded=true') !== false)
            {
                header('Content-Type: '. $this->result['content-type']);
            }
            $this->result['body'] = $body;
        }
    }


    /**
     * Create and return a Div HTML element if response type of text/html,
     * a div containing a PRE HTML element if of type text/plain, and
     * the response itself if of any other MIME type.
     *
     * @param boolean wheather to highlight alternating rows if response
     *  of type plain/text.
     * @return string div of CGI output
     */
    public function getDiv($highlightRowsIfPlainText = false)
    {
        if ($highlightRowsIfPlainText and !empty($this->options['QUERY_STRING']))
        {
            $qs = $this->options['QUERY_STRING'];
            if
            (
                strpos($qs, 'content-type=text/plain') !== false
                or strpos($qs, 'annotate') !== false
            )
            {
                $highlightRowsIfPlainText = false;
            }
        }
        
        if ($this->result['content-type'] == 'text/html')
        {
            // get contents of BODY by copying all child nodes
            if (empty($this->doc))
            {
                return '<div id="cgi_wrapper"></div>';
            }

            $body = $this->doc->getElementsByTagName('body')->item(0);
            $doc = new DOMDocument();
            
            foreach ($body->childNodes as $child)
            {
                $doc->appendChild($doc->importNode($child, true));
            }

            if ($highlightRowsIfPlainText)
            {
                foreach ($doc->childNodes as $child)
                {
                    if (get_class($child) == 'DOMElement')
                    {
                        $pre = $doc->getElementsByTagName('pre');
                        $i = $pre->length - 1;
                        while ($i > -1)
                        {
                            $p = $pre->item($i);
                            $div = $doc->createElement('div');
                            $p->parentNode->replaceChild($div, $p);
                            $d = new DOMDocument();
                            @$d->loadHTML($this->linesToList($p->nodeValue));
                            foreach ($d->childNodes as $dc)
                            {
                                if (get_class($dc) != 'DOMDocumentType')
                                {
                                    $div->appendChild($doc->importNode($dc, true));
                                }
                            }
                            $i--;
                        }
                    }
                }
            }

            return '<div id="cgi_wrapper">'. $doc->saveHTML() .'</div>';
        }
        else if ($this->result['content-type'] == 'text/plain')
        {
            if (! $highlightRowsIfPlainText)
            {
                $body = '<pre>'. htmlentities($this->result['body']) .'</pre>';
            }
            else
            {
                $body = $this->linesToList($this->result['body']);
            }

            return '<div id="cgi_wrapper">'. $body .'</div>';
        }
        else
        {
            return $this->result['body'];
        }
    }

    /*
     * Convert text file rows to spans in order to allow altenrate row
     * highlighting.
     *
     * @param string text to convert
     * @return string text as spans
     */
    private function linesToList($pre = null)
    {
        $rows = explode("\n", $pre);
        $count = 0;
        $class = array
        (
            0   => 'even',
            1   => 'odd',
        );

        foreach ($rows as $row)
        {
            $row = strlen(trim($row)) == 0 ? '&nbsp;' : $row;
            $output[] = '<li class="'. $class[$count % 2] .'">'. $row .'</li>';
            $count++;
        }

        if ($count == 1)
        {
            return '<div id="div_pre">'. $pre .'</div>';
        }
        else
        {
            return '<div id="div_pre">'. implode("\n", $output) .'</div>';
        }
    }

    /**
     * Make a proxied URL.
     *
     * @param string URI as present in CGI response.
     */
    private function makeURL($path = null)
    {
        if (empty($path))
        {
            if (isset($this->options['GET']))
            {
                return $this->options['PHP_SCRIPT'] .'?'. $this->options['GET'];
            }
            else
            {
                return $this->options['PHP_SCRIPT'];
            }
        }
        else
        {
            $path = $this->makePath($path);
            if (isset($this->options['GET']))
            {
                return $this->options['PHP_SCRIPT'] .'?'. $this->options['GET'] .'&href='. urlencode($path);
            }
            else
            {
                return $this->options['PHP_SCRIPT'] .'?href='. urlencode($path);
            }
        }
    }


    /**
     * Make a URI that is proxied via the instantator of this class based
     * on URI in CGI response. Handles all types of URIs including those
     * that are relative to the current document and those fully qualifed
     * to HTTP root.
     *
     * @param string URI from CGI response.
     */
    private function makePath($href = null)
    {
        $dir = isset($this->options['PATH_INFO']) ? $this->options['PATH_INFO'] : null;

        if (self::beginsWith('/', $href))
        {
            // relative to "root"
            return substr($href, strlen($this->options['SCRIPT_NAME']));
        }
        else if (self::beginsWith('..', $href))
        {
            // go "up" a directory
            return ereg_replace('[^/]*/?$', '', $dir);
        }
        else
        {
            // relative to current location
            if (self::beginsWith('./', $href))
            {
                $href = substr($href, 2);
            }

            if (self::endsWith('/', $dir))
            {
                return $dir . $href;
            }
            else
            {
                if (($pos = strpos($href, '?')) !== false)
                {
                    return $dir . substr($href, $pos);
                }
                else if (($pos = strpos($href, '#')) !== false)
                {
                    return $dir . substr($href, $pos);
                }
                else
                {
                    return dirname($dir) .'/'. $href;
                }
            }
        }
    }


    /**
     * Validate supplied options for CGI environment using callers supplied
     * options in preference to those set by $_SERVER.
     *
     * @param array of options.
     */
    private function setOptions($options = null)
    {
        if (isset($_GET['href']))
        {
            $href = urldecode($_GET['href']);

            if (($pos = strpos($href, '#')) !== false)
            {
                $href = substr($href, 0, $pos);
            }

            $options['REQUEST_URI'] .= $href;

            if (strpos($href, '?') !== false)
            {
                $tmp = explode('?', $href);
                $href = $tmp[0];
                $options['QUERY_STRING'] = $tmp[1];
            }

            $options['PATH_INFO'] = $href;
        }
        else
        {
            $options['PATH_INFO'] = '/';
        }

        $this->options = $options;
        $this->validateOptions();
    }

    /**
     * Verify at least the minimal required options for execution of CGI script
     * are supplied.
     *
     * @throws exception if a required option is not provided.
     */
    private function validateOptions()
    {
        $required = array
        (
            'SCRIPT_FILENAME',
            'REQUEST_URI',
            'PHP_SCRIPT',
        );

        foreach ($required as $r)
        {
            if (!(isset($this->options[$r]) or empty($this->options[$r])))
            {
                throw new Exception("Required option '$r' not set!");
            }
        }
    }


    /**
     * Create the environmental variables based on caller supplied options and
     * those set by PHP $_SERVER (preference to caller).
     *
     * @throws exception if suspect environmental value is supplied.
     */
    private function setEnv()
    {
        // whitelist of environmental values to pass through -- ignore others!
        // going with minimalist route -- you will likely need to add
        // more depending on what the CGI depends on...
        $env = array
        (
            'DOCUMENT_ROOT'     => null,
            'SCRIPT_FILENAME'   => null,
            'SCRIPT_NAME'       => null,
            'REQUEST_URI'       => null,
            'QUERY_STRING'      => null,
            'PATH_INFO'         => null,
        );

        // value for env. var from $_SERVER or $this->options (options wins)
        foreach (array_keys($env) as $key)
        {
            if (array_key_exists($key, $this->options) and !empty($this->options[$key]))
            {
                putenv($key .'='. $this->options[$key]);
            }
            else if (array_key_exists($key, $_SERVER) and !empty($_SERVER[$key]))
            {
                putenv($key .'='. $_SERVER[$key]);
            }
        }
    }


    /**
     * Return plain reponse body.
     *
     * @return string unprocessed CGI response body.
     */
    public function getBody()
    {
        return $this->result['body'];
    }


    /**
     * Return plain response header.
     *
     * @return string unprocessed CGI response header.
     */
    public function getHeader()
    {
        return $this->result['header'];
    }


    /**
     * Return content-type header as returned by CGI script.
     *
     * @return string content-type returned by call to CGI script.
     */
    public function getContentType()
    {
        return $this->result['content-type'];
    }

    /**
     * Return environmental variable string as assembled by class.
     *
     * @return string environmental string as passed to wrapper script.
     */
    public function getEnv()
    {
        return $this->cgi['env'];
    }


    /**
     * Glue function that simply checks if string begins with a certain
     * string.
     *
     * @param string the needle being looked for.
     * @param string the haystack being searched.
     * @return true if haystack begins with needle.
     */
    private static function beginsWith($needle = null, $haystack = null)
    {
        if (empty($haystack) or empty($needle))
        {
            return false;
        }

        if (substr($haystack, 0, strlen($needle)) == $needle)
        {
            return true;
        }
        else
        {
            return false;
        }
    }


    /**
     * Glue function that checks if a string ends with a certain
     * string.
     *
     * @param string the needle being looked for.
     * @param string the haystack being searched.
     * @return true if haystack ends with needle.
     */
    private static function endsWith($needle = null, $haystack = null)
    {
        if (empty($haystack) or empty($needle))
        {
            return false;
        }

        if (substr($haystack, -1 * strlen($needle)) == $needle)
        {
            return true;
        }
        else
        {
            return false;
        }
    }
}
?>
