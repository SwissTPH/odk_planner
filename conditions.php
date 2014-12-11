<?php
if (!defined('MAGIC')) die('!?');


class ParserException extends Exception
{
    public function __construct($message, $code = 0, Exception $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}

class LogicNodeException extends Exception
{
    public function __construct($message, $code = 0, Exception $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}

class LogicNode
{

    /**
     * only leaf nodes have an <code>$expression</code>; inner nodes
     * have an <code>$operator</code> and <code>$children</code>. operator
     * precedence <code>'&amp' > '|'</code> is automatically enforced.
     */

    function LogicNode($form_or_node, $name=null, $eq=null, $value=null) {
        // always : null(expression) == (count(ops)>0) == (count(children)>0)
        if ($name !== null) {
            $this->expression = array(
                'form'=>$form_or_node,
                'name'=>$name,
                'eq'=>$eq,
                'value'=>$value
            );
            $this->ops = array();
            $this->children = array();
        } else {
            $this->expression = null;
            $this->ops = array(null);
            $this->children = array($form_or_node);
        }
    }

    function add($op, $node) {
        if ($this->expression) {
            // leaf node -> inner node
            $this->children = array(
                new LogicNode(
                    $this->expression['form'],
                    $this->expression['name'],
                    $this->expression['eq'],
                    $this->expression['value']
                ),
                $node
            );
            $this->ops = array(null, $op);
            $this->expression = null;
        } else {
            array_push($this->ops, $op);
            array_push($this->children, $node);
        }
    }

    function evaluate($data) {

        if ($this->expression) {

            $form = $this->expression['form'];
            $name = $this->expression['name'];
            $eq = $this->expression['eq'];
            $value = $this->expression['value'];

            if (!array_key_exists($form, $data)) {
                return false;
            }
            foreach($data[$form] as $uri=>$d) {
                // if there are multiple instances of the same form in the data,
                // then ALL must match (arbitrary but at least defined behaviour)
                if (($eq === '<' && $d[$name] >= $value) ||
                    ($eq === '>' && $d[$name] <= $value) ||
                    ($eq === '=' && $d[$name] != $value) ||
                    ($eq === '!=' && $d[$name] == $value)) {
                        return false;
                    }
            }

            return true;
        }

        // evaluate and-ed expressions grouped, then or the result
        $orval = false;
        $i = 0;
        while($i < count($this->ops)) {
            $andval = $this->children[$i]->evaluate($data);
            $i++;
            while($i < count($this->ops) && $this->ops[$i] === '&') {
                $andval &= $this->children[$i]->evaluate($data);
                $i++;
            }
            $orval |= $andval;
        }

        return $orval ? true : false;
    }

    function htmldump($prefix='', $i=-1) {
        $i++;
        echo htmlentities($prefix . "NODE=$i\n");
        if ($this->expression) {
            echo htmlentities($prefix . "form={$this->expression['form']}\n");
            echo htmlentities($prefix . "name={$this->expression['name']}\n");
            echo htmlentities($prefix . "eq={$this->expression['eq']}\n");
            echo htmlentities($prefix . "value={$this->expression['value']}\n");
        } else {
            echo htmlentities($prefix . count($this->children) . " children:\n");
            foreach($this->children as $i=>$child) {
                echo htmlentities($prefix . "op={$this->ops[$i]}\n");
                $i = $child->htmldump($prefix . '  ', $i);
            }
        }
        return $i;
    }

    function count() {
        if ($this->expression)
            return 1;
        $ret = 0;
        foreach($this->children as $child)
            $ret += $child->count();
        return $ret;
    }
}

class Condition
{

    function err($msg, $i, $state) {
        throw new ParserException($msg . 
            " : state=$state, tokens[$i]=({$this->tokens[$i][0]}, {$this->tokens[$i][1]})");
    }

    function Condition($default_form, $string) {

        $this->tokens = $this->tokens($string);
        #echo '<pre>', htmlentities(print_r($tokens, true)), '</pre>';

        $this->params = array();
        $state = 0;
        $stack = array();
        $opstack = array();
        $form = $name = $eq = $value = $op = $node = null;

        foreach($this->tokens as $i=>$token) {

            //       FORM\NAME   =   "value"  [&,|]
            // state     0       1      2       3
            //       (value)    (op) (value)  (op)

            if ($token[1] === 'bracket') {

                // brackets : modify $stack, don't change $state

                if ($token[0] === '(') {
                    if ($state % 4 !== 0) {
                        $this->err('unexpected "("', $i, $state);
                    }
                    array_push($stack, $node);
                    array_push($opstack, $op);
                    $node = null;

                } else {
                    if ($state % 4 !== 3) {
                        $this->err('unexpected ")"', $i, $state);
                    }
                    if (!$stack)
                        $this->err('unmatched closing bracket', $i, $state);
                    if (!$node)
                        $this->err('empty brackets', $i, $state);
                    $parent = array_pop($stack);
                    if ($parent) {
                        $op = array_pop($opstack);
                        $parent->add($op, $node);
                        $node = $parent;
                    } else {
                        // expression started with '('
                        $node = new LogicNode($node);
                    }
                }

                continue; // don't increment $state 
            }

            // not bracket : modify $state, update $form, $name, $eq, $value, $op

            if ($state % 4 === 0) {

                if ($token[1] !== 'thing') {
                    $this->err('expected "thing" or "bracket"', $i, $state);
                }

                $parts = explode('\\', $token[0]);
                if (count($parts) === 1) {
                    $form = $default_form;
                    $name = $parts[0];
                } else if (count($parts) === 2) {
                    $form = $parts[0];
                    $name = $parts[1];
                } else {
                    $this->err('expected "[form\\]name"', $i, $state);
                }

            } else if ($state % 4 === 1) {
                if ($token[1] !== 'eq' || !in_array($token[0],
                    array('<', '>', '=', '!='))) {
                    $this->err('expected "<", ">", "=" or "!="', $i, $state);
                }
                $eq = $token[0];

            } else if ($state % 4 === 2) {
                if ($token[1] !== 'thing') {
                    $this->err('expected "thing"', $i, $state);
                }
                $value = $token[0];

                // create node
                if ($node) {
                    $node->add($op, new LogicNode($form, $name, $eq, $value));
                } else {
                    $node = new LogicNode($form, $name, $eq, $value);
                }
                $this->add_param($form, $name);

            } else {
                if ($token[1] !== 'op' || !in_array($token[0], array('|', '&'))) {
                    $this->err('expected "&" or "|"', $i, $state);
                }
                $op = $token[0];
            } // end if ($i % 4 == 0)

            $state++;
        } // end foreach($tokens)

        if ($state % 4 !== 3) {
            $this->err('expression must end with "value" or ")"', $i, $state);
        }

        $this->root = $node;
    }

    function tokens($string) {

        $classes = array(
            'whitespace' => '\\s',
            'thing' => '[a-z0-9_\\.\\\\]',
            'eq' => '[!=<>]',
            'op' => '[&|]',
            'bracket' => '[()]'
        );

        $tokens = array();
        for($inquote=false,$lc='whitespace',$i=$j=0; $i<strlen($string); $i++) {
            $c = substr($string, $i, 1);

            if ($c === '"') {
                if ($inquote) {
                    array_push($tokens, array(substr($string, $j, $i-$j), 'thing'));
                    $lc = 'whitespace';
                } else {
                    if($lc !== 'whitespace') {
                        array_push($tokens, array(substr($string, $j, $i-$j), $lc));
                    }
                }
                $j = $i + 1;
                $inquote = !$inquote;
                continue;
            }
            if ($inquote) {
                continue;
            }

            $nc = null;
            foreach($classes as $cl=>$re) {
                if (preg_match('/^'.$re.'$/i', $c)) {
                    $nc = $cl;
                    break;
                }
            }
            if ($nc === null) {
                throw new ParserException("cannot parse character #$i='{$c}'");
            }
            if ($nc !== $lc) {
                if($lc !== 'whitespace') {
                    array_push($tokens, array(substr($string, $j, $i-$j), $lc));
                }
                #echo "set j=$i c=$c nc=$nc lc=$lc\n";
                $j = $i;
                $lc = $nc;
            }
        }
        if ($inquote) {
            throw new ParserException("unmatched '\"'");
        }
        if($lc !== 'whitespace') {
            array_push($tokens, array(substr($string, $j, $i-$j), $lc));
        }

        return $tokens;
    }

    function get_params() {
        return $this->params;
    }

    function add_param($form, $name) {
        if (!array_key_exists($form, $this->params)) {
            $this->params[$form] = array();
        }
        if (!in_array($name, $this->params[$form])) {
            array_push($this->params[$form], $name);
        }
    }

    function evaluate($data) {
        return $this->root->evaluate($data);
    }

    /*
    function where($forms) {
        $parts = array();
        foreach($this->expressions as $e) {
            $form = $forms->get($e[0]);
            if (!$form) {
                alert('could not evaluate part of expression: form "' .
                    $e[0] . '" not found', 'error');
                continue;
            }
            $path = $form->find_path($e[1]);
            if (!$path) {
                alert('could not evaluate part of expression: field "' .
                    $e[0] . '\\' . $e[1] . '" not found', 'error');
                continue;
            }
            $mapping = $form->mapping[$path];
            $part = "`$mapping[0]`.`$mapping[1]` $e[2] '" .
                mysql_real_escape_string($e[3]) . "'";
            array_push($parts, $part);
        }
        return implode(' AND ', $parts);
    }
     */

}


