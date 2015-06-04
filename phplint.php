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
			case 'PhpParser\Node\Expr\MethodCall':
				parse_method_call($stmt, $vars, $classes);
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
	$vars[$name]['c']++;
	$vars[$name]['v'] = $var;
	$c = get_class($assign->expr);
	switch ($c) {
		case 'PhpParser\Node\Expr\New_':
			$t = strtolower($assign->expr->class->parts[0]);
			if (!$classes[$t] && !class_exists($t))	{
				//实例化不存在的类
			}
			$vars[$name]['t'] = $t; 
			break;
		case 'PhpParser\Node\Expr\Array_':
			break;
	}
}

function parse_function($fun, &$functions) {
	$name = strtolower($fun->name);
	$vars = array();
	$classes = array();
	$funs = array();
	foreach ($fun->params as $param) {
		$name = $param->name;
		$vars[$name]['c']++;
		$vars[$name]['v'] = $param;
	}
	if ($functions[$name] || function_exists($name)) {
		//方法重复定义	
	}
	$functions[$name] = $fun;

	parse_stmts($fun->stmts, $vars, $classes, $funs);

}

function parse_call($call, $functions) {
	$name = $call->name->parts[0];
	if (!$functions[$name] && !function_exists($name)) {
		//调用不存在的方法	
	}
}

function parse_method_call($call, $vars, $classes) {
	$var_name = $call->var->name;
	$method_name = strtolower($call->name);
	$var = $vars[$var_name];
	if (!$var) {
		//调用未初始化变量的方法
	} else if (!$var['t']) {
		//调用非对象的方法	
	} else {
		$c = $var['t'];
		$class = $classes[$c];
		if (!$class && !class_exists($c)) {
			//调用未知类型对象的方法
		} else {
			if ($class) {
			
			} else {
				$methods = get_class_methods($c);
				$n = 0;
				foreach ($methods as $m) {
					if ($method_name == strtolower($m)) {
						$n++;
						break;
					}
				}
				if (!$n) {
					//调用方法不存在
				}
			}
		}
	}
}

function parse_class($class, &$classes) {
	$name = strtolower($class->name);
	if ($classes[$name] || class_exists($name)) {
		//类重复定义			
	}
	$methods = array();
	$properties = array();

	foreach ($class->stmts as $stmt) {
		$c = get_class($stmt);
		switch ($c) {
			case 'PhpParser\Node\Stmt\ClassMethod':
				parse_method($class, $stmt);
				$n = strtolower($stmt->name);
				$methods[$n] = $stmt;
				break;
			case 'PhpParser\Node\Stmt\Property':
				$n = $stmt->props[0]->name;
				$properties[$n] = $stmt;
				break;
		}
	}
	$classes[$name]['v'] = $class;
	$classes[$name]['methods'] = $methods;
	$classes[$name]['properties'] = $properties;
}

function parse_method($class, $method) {
	$vars = array();
	$functions = array();
	$classes = array();
	foreach ($method->params as $param) {
		$name = $param->name;
		$vars[$name]['v'] = $param;
		$vars[$name]['c']++;
	}
	parse_stmts($method->stmts, $vars, $classes, $functions);
}
