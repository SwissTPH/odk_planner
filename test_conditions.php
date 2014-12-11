<style>
.ok { color:green; font-weight:bold; }
.error { color:red; font-weight:bold; }
</style>
<pre>
<?php
$_SERVER['SERVER_ADDR'] === '127.0.0.1' || $_SERVER['SERVER_ADDR'] === '::1' || die('test.php runs only on localhost (127.0.0.1 or ::1)');
define('MAGIC', '');

include('conditions.php');


function report($ok, $msg, $error_info=null) {
    echo "$msg...";
    if ($ok) {
        echo "<span class=ok>OK</span>";
    } else {
        echo "<span class=error>FAILED!</span>";
        if ($error_info) {
            echo " : $error_info";
        }
    }
    echo "\n";
}

class CompareTreeException extends Exception
{
    public function __construct($message, $code = 0, Exception $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}


function compare_tree($node, $array, $i=-1) {
    $i++;
    if ($array[0]) { // expression
        if (!$node->expression) {
            throw new CompareTreeException("node $i should be leaf");
        }
        if (
            $array[0][0] !== $node->expression['form'] ||
            $array[0][1] !== $node->expression['name'] ||
            $array[0][2] !== $node->expression['eq'] ||
            $array[0][3] !== $node->expression['value']
        ) {
            throw new CompareTreeException("node $i expression mismatch " .
                implode(',', $array[0]));
        }

    } else {
        if (count($array[1]) !== count($node->children)) {
            throw new CompareTreeException("node $i not same number of children");
        }
        foreach($node->children as $j=>$child) {
            if ($array[2][$j] !== $node->ops[$j]) {
                throw new CompareTreeException("node $i operator $j mismatch : array " .
                $array[2][$j] . " node " . $node->ops[$i] . " i=$i j=$j");
            }
            $i = compare_tree($child, $array[1][$j], $i);
        }
    }
    return $i;
}


function test_tree($string, $tree) {

    $c = new Condition("FORM", $string);

    try {
        $n = compare_tree($c->root, $tree) + 1;
        report(true, "test_tree('$string') : all $n node(s) match");
    } catch(CompareTreeException $e) {
        report(false, "test_tree('$string') : " . $e->getMessage());
        #echo '<pre>', htmlentities(print_r($c->tokens, true)), '</pre>';
        $c->root->htmldump("\t");
    }

}

function node($a, $b, $c=null, $d=null) {
    if ($c === null) {
        # not expression : null, children, ops
        return array(null, $a, $b);
    } else {
        # expression : form, field, eq, value
        return array(array($a, $b, $c, $d), array(), array());
    }
}

test_tree('FIELD=1',
    node('FORM', 'FIELD', '=', '1'));

test_tree('FIELD!=1',
    node('FORM', 'FIELD', '!=', '1'));

test_tree('XXX\\FIELD=1',
    node('XXX', 'FIELD', '=', '1'));

test_tree('BLAH=A & XXX\\FIELD=1',
    node(array(
        node('FORM', 'BLAH', '=', 'A'),
        node('XXX', 'FIELD', '=', '1')
    ), array(null, '&')));

test_tree('BLAH = "A" & XXX\\FIELD   =1',
    node(array(
        node('FORM', 'BLAH', '=', 'A'),
        node('XXX', 'FIELD', '=', '1')
    ), array(null, '&')));

test_tree('BLAH="A"&XXX\\FIELD=1&YYY\\NAME="Franz Xaver Huber"',
    node(array(
        node('FORM', 'BLAH', '=', 'A'),
        node('XXX', 'FIELD', '=', '1'),
        node('YYY', 'NAME', '=', 'Franz Xaver Huber')
    ), array(null, '&', '&')));

test_tree('(FIELD=1)',
    node(array(
        node('FORM', 'FIELD', '=', '1'),
    ), array(null)));

test_tree('(FIELD=1) & FIELD=0',
    node(array(
        node('FORM', 'FIELD', '=', '1'),
        node('FORM', 'FIELD', '=', '0')
    ), array(null, '&')));

test_tree('FIELD=1 & (FIELD=0)',
    node(array(
        node('FORM', 'FIELD', '=', '1'),
        node('FORM', 'FIELD', '=', '0')
    ), array(null, '&')));

test_tree('(FIELD=1 | FIELD=0) & OTHER=0',
    node(array(
        node(array(
            node('FORM', 'FIELD', '=', '1'),
            node('FORM', 'FIELD', '=', '0')
        ), array(null, '|')),
        node('FORM', 'OTHER', '=', '0')),
    array(null, '&')));

test_tree('FIELD=1 & (OTHER=1 | OTHER=0)',
    node(array(
        node('FORM', 'FIELD', '=', '1'),
        node(array(
            node('FORM', 'OTHER', '=', '1'),
            node('FORM', 'OTHER', '=', '0')
        ), array(null, '|'))),
    array(null, '&')));

function test_eval($string, $data, $result) {

    $c = new Condition("FORM", $string);
    $r = $c->evaluate(array('FORM'=>array('uri'=>$data)));

    report($result === $r, "test_eval('$string')===$result");
}


test_eval('A=1', array('A'=>1), true);
test_eval('A=1', array('A'=>0), false);
test_eval('A!=1', array('A'=>1), false);
test_eval('A!=1', array('A'=>0), true);
test_eval('A=1 & B=0', array('A'=>1, 'B'=>0), true);
test_eval('A=1 | A=0', array('A'=>0), true);
test_eval('A=1 | A=0', array('A'=>2), false);
test_eval('A=1 | A=0 & B=1', array('A'=>0, 'B'=>0), false);
test_eval('A=1 | A=0 & B=1 | A=0 & B=0', array('A'=>0, 'B'=>0), true);
test_eval('(A=1 | A=0) & B=0', array('A'=>0, 'B'=>0), true);
test_eval('(A=1 | A=0) & B=0', array('A'=>0, 'B'=>1), false);
test_eval('A=1 & (B=1 | C=1)', array('A'=>1, 'B'=>0, 'C'=>0), false);

function test_parser_exception($string) {
    try {
        new Condition('FORM', $string);
    } catch(ParserException $e) {
        report(true, "test_parser_exception('$string')");
        return;
    }
    report(false, "test_parser_exception('$string')", "expected ParserException");
}

test_parser_exception('BLAH');
test_parser_exception('BLAH,=1');
#test_parser_exception('BLAH.X=1');
test_parser_exception('BLAH-X=1');
test_parser_exception('BLAH==1');
test_parser_exception('BLAH>=1');
test_parser_exception('BLAH=!1');
test_parser_exception('BLAH=1 NAH=2');
test_parser_exception('BLAH\\DRAH\\FAH=1');
test_parser_exception('A=1 & B=2 &');
test_parser_exception('A=1 & B=2 & C');
test_parser_exception('A=1 & B=2 & C=');
test_parser_exception('A=1 & B=2 & =3');
test_parser_exception('( A=1 & B=2 & C=');
test_parser_exception(') A=1 & B=2 & C=');
test_parser_exception('A=1 & B=2 & C= & ()');
test_parser_exception('A=1 && B=2 & C=');
test_parser_exception('A=1 &| B=2 & C=');
die;

function test_evaluation($string, $data, $result) {
    $c = new Condition('FORM', $string);
    $x = $c->evaluate($data);
    report($result === $x, "evaluating '$string' == '$result'", "got '$x'");
}

test_evaluation('A=1', array('FORM'=>array('uri'=>array('A'=>1))), true);
test_evaluation('A=1', array('FORM'=>array('uri'=>array('A'=>2))), false);
test_evaluation('A=1 & B=2', array('FORM'=>array('uri'=>array('A'=>1, 'B'=>2))), true);
test_evaluation('A=1 & B=2', array('FORM'=>array('uri'=>array('A'=>1, 'B'=>1))), false);
test_evaluation('A=1 & X\\B=2', array('FORM'=>array('uri'=>array('A'=>1, 'B'=>1)), 'X'=>array('uri'=>array('B'=>2))), true);
test_evaluation('A=1 & X\\B=2 & B=1', array('FORM'=>array('uri'=>array('A'=>1, 'B'=>1)), 'X'=>array('uri'=>array('B'=>2))), true);
test_evaluation('A=1 & X\\B=2 & B=1', array('FORM'=>array('uri'=>array('A'=>1)), 'X'=>array('uri'=>array('B'=>2))), false);


require_once('util.php');

render_alerts();

