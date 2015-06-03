<?php
require(__DIR__ . '/lib/bootstrap.php');

$code = file_get_contents($argv[1]);
$parser = new PhpParser\Parser(new PhpParser\Lexer);
$stmts = $parser->parse($code);
$vars = array();
$classes = array();
$functions = array();

parse_stmts($stmts, $vars, $classes, $functions);

function parse_stmts($stmts, &$vars, &$classes, &$functions) {
	foreach ($stmts as $stmt) {
		$c = get_class($stmt);
		switch ($c) {
			case 'PhpParser\Node\Stmt\Function_':
				parse_function($stmt, $functions);
				break;
			case 'PhpParser\Node\Stmt\Class_':
				parse_class($stmt, $classes);
				break;
			case 'PhpParser\Node\Expr\Include_':
				parse_include($stmt);				
				break;
			case 'PhpParser\Node\Stmt\For_':
			case 'PhpParser\Node\Stmt\While_':
			case 'PhpParser\Node\Stmt\If_':
				parse_stmts($stmt->stmts, $vars, $classes, $functions);
				break;
			case 'PhpParser\Node\Expr\Assign':
				parse_assign($stmt, $vars, $classes);
				break;
			case 'PhpParser\Node\Expr\FuncCall':
				parse_call($stmt, $functions);
				break;
		}
	}
}

function parse_include($stmt) {
	$expr = $stmt->expr;
}

function parse_assign($assign, &$vars, $classes) {
	$var = $assign->var;
	$name = $var->name;
	$vars[$name]['count']++;
	$vars[$name]['v'] = $var;
	$c = get_class($assign->expr);
	switch ($c) {
		case 'PhpParser\Node\Expr\New_':
			$t = strtolower($assign->expr->class->parts[0]);
			if (!$classes[$t] && !class_exists($t))	{
			}
			$vars[$name]['t'] = $t; 
			break;
	}
}

function parse_function($fun, &$functions) {
	$name = $fun->name;
	$params = array();
	foreach ($fun->params as $param) {
		$params[$param->name] = $param;		
	}
	if ($functions[$name] || function_exists($name)) {
	
	} else {
		$functions[$name] = $fun;
	}
}

function parse_call($call, $functions) {
	$name = $call->name->parts[0];
	if (!$functions[$name] && !function_exists($name)) {
	
	}
}

function parse_class($class, &$classes) {
	$name = strtolower($class->name);
	if ($classes[$name] || class_exists($name)) {
	
	} else {
		foreach ($class->stmts as $stmt) {
			$c = get_class($stmt);
			switch ($c) {
				case 'PhpParser\Node\Stmt\ClassMethod':
					parse_method($class, $stmt);
			}
		}
		$classes[$name] = $class;
	}
}

function parse_method($class, $method) {
	$params = array();
	foreach ($method->params as $param) {
		$params[$param->name] = $param;		
	}
	foreach ($method->stmts as $stmt) {
	
	}
}
