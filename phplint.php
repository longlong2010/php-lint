#!/usr/bin/php
<?php
require(__DIR__ . '/lib/bootstrap.php');

$vars = array();
$classes = array();
$functions = array();

parse_file($argv[1], $vars, $classes, $functions);

function check_unused_variable($vars) {
	foreach ($vars as $n => $var) {
		if ($var['c'] <= 1) {
			print_error("Unused variable \${$n}", $file, $var['v']->getAttribute('startLine', -1));
		}
	}
}

function parse_file($file, &$vars, &$classes, &$functions) {
	$code = file_get_contents($file);
	$parser = new PhpParser\Parser(new PhpParser\Lexer);
	$stmts = $parser->parse($code);
	parse_stmts($stmts, $vars, $classes, $functions, $file);
	check_unused_variable($vars);
}

function parse_namespace($ns, &$vars, &$classes, &$functions, $file) {
	$name = strtolower(implode('\\', $ns->name->parts)) . '\\';
	parse_stmts($ns->stmts, $vars, $classes, $functions, $file, $name);
}

function parse_stmts($stmts, &$vars, &$classes, &$functions, $file, $ns = '', $class = null) {
	foreach ($stmts as $stmt) {
		$c = get_class($stmt);
		switch ($c) {
			case 'PhpParser\Node\Stmt\Class_':
				parse_class_definition($stmt, $classes, $file, $ns);
				break;
			case 'PhpParser\Node\Stmt\Function_':
				parse_function_definition($stmt, $functions, $file, $ns);
				break;
		}
	}

	foreach ($stmts as $stmt) {
		$c = get_class($stmt);
		switch ($c) {
			case 'PhpParser\Node\Stmt\Namespace_':
				parse_namespace($stmt, $vars, $classes, $functions, $file);
				break;
			case 'PhpParser\Node\Stmt\Function_':
				parse_function($stmt, $functions, $file, $ns);
				break;
			case 'PhpParser\Node\Stmt\Class_':
				parse_class($stmt, $classes, $functions, $file, $ns);
				break;
			case 'PhpParser\Node\Expr\Include_':
				parse_include($stmt, $file);
				break;
			case 'PhpParser\Node\Stmt\For_':
			case 'PhpParser\Node\Stmt\While_':
			case 'PhpParser\Node\Stmt\If_':
				parse_stmts($stmt->stmts, $vars, $classes, $functions, $file, $ns);
				break;
			case 'PhpParser\Node\Stmt\Use_':
				break;
			case 'PhpParser\Node\Stmt\Echo_':
				parse_exprs($stmt->exprs, $vars, $file);
				break;
			case 'PhpParser\Node\Expr\Assign':
				parse_assign($stmt, $vars, $classes, $functions, $file, $ns);
				break;
			case 'PhpParser\Node\Expr\FuncCall':
				parse_call($stmt, $vars, $functions, $file, $ns);
				break;
			case 'PhpParser\Node\Expr\MethodCall':
				parse_method_call($stmt, $vars, $classes, $file, $class);
				break;
		}
	}
}

function parse_exprs($exprs, &$vars, $file) {
	foreach ($exprs as $expr) {
		$c = get_class($expr);
		switch ($c) {
			case 'PhpParser\Node\Expr\Variable':
				$n = $expr->name;
				if (!$vars[$n]) {
					$vars[$n]['v'] = $expr;
				}
				$vars[$n]['c']++;
				break;
			case 'PhpParser\Node\Expr\PropertyFetch':
				break;
		}
	}
}

function parse_include($stmt, $file) {
	$expr = $stmt->expr;
}

function parse_assign($assign, &$vars, $classes, $functions, $file, $ns = '') {
	$var = $assign->var;
	$name = $var->name;
	$vars[$name]['c']++;
	$vars[$name]['v'] = $var;
	$c = get_class($assign->expr);
	switch ($c) {
		case 'PhpParser\Node\Expr\New_':
			switch (get_class($assign->expr->class)) {
				case 'PhpParser\Node\Name\FullyQualified':
					$t = strtolower(implode('\\', $assign->expr->class->parts));
					break;
				case 'PhpParser\Node\Name':
					$t = $ns . strtolower($assign->expr->class->parts[0]);
					break;
			}
			if (!$classes[$t] && !class_exists($t))	{
				//实例化不存在的类
				print_error("Class '{$t}' not found", $file, $assign->getAttribute('startLine', -1));
			}
			$vars[$name]['t'] = $t;
			break;
		case 'PhpParser\Node\Expr\FuncCall':
			parse_call($assign->expr, $vars, $functions, $file, $ns);
		case 'PhpParser\Node\Expr\Array_':
		default:
			$vars[$name]['t'] = '';
			break;
	}
}

function parse_function_definition($fun, &$functions, $file, $ns = '') {
	$name = strtolower($ns . $fun->name);
	$vars = array();
	$classes = array();
	$funs = array();
	foreach ($fun->params as $param) {
		$n = $param->name;
		$vars[$n]['c']++;
		$vars[$n]['v'] = $param;
	}
	if ($functions[$name] || function_exists($name)) {
		//方法重复定义
		print_error("Cannot redeclare {$name}()", $file, $fun->getAttribute('startLine', -1));
	}
	$functions[$name] = $fun;
}


function parse_function($fun, &$functions, $file, $ns = '') {
	$name = strtolower($ns . $fun->name);
	$vars = array();
	$classes = array();
	
	foreach ($fun->params as $param) {
		$n = $param->name;
		$vars[$n]['c']++;
		$vars[$n]['v'] = $param;
	}
	
	parse_stmts($fun->stmts, $vars, $classes, $functions, $file);
	check_unused_variable($vars);
}

function parse_call($call, &$vars, $functions, $file, $ns) {
	$name = $ns . strtolower(implode('\\', $call->name->parts));
	if (!$functions[$name] && !function_exists($name)) {
		//调用不存在的方法
		print_error("Call to undefined function {$name}", $file, $call->getAttribute('startLine', -1));
	}
	foreach ($call->args as $arg) {
		$c = get_class($arg->value);
		switch ($c) {
			case 'PhpParser\Node\Expr\Variable':
				$n = $arg->value->name;
				if (!$vars[$n]) {
					//传递空参数
					$vars[$n]['v'] = $arg->value;
				}
				$vars[$n]['c']++;
				break;
		}
	}
}

function parse_method_call($call, &$vars, $classes, $file, $class = '') {
	$var_name = $call->var->name;
	$method_name = strtolower($call->name);
	$var = $vars[$var_name];

	foreach ($call->args as $arg) {
		$c = get_class($arg->value);
		switch ($c) {
			case 'PhpParser\Node\Expr\Variable':
				$n = $arg->value->name;
				if (!$vars[$n]) {
					//传递空参数
					$vars[$n]['v'] = $arg->value;
				}
				$vars[$n]['c']++;
				break;
		}
	}

	if ($var_name == 'this' && $class) {
		$methods = $c['methods'];
		$m = $methods[$method_name];
		if (!$m) {
			$name = $class['v']->name;
			print_error("Call to undefined method {$name}::{$method_name}()", $file, $call->getAttribute('startLine', -1));
		}
	} else if (!$var) {
		//调用未初始化变量的方法
		print_error("Call to a member function {$method_name}() on null", $file, $call->getAttribute('startLine', -1));
	} else if (!$var['t']) {
		//调用非对象的方法
		print_error("Call to a member function {$method_name}() on non-object", $file, $call->getAttribute('startLine', -1));
	} else {
		$c = $classes[$var['t']];
		if (!$c && !class_exists($c)) {
			//调用未知类型对象的方法
		} else {
			if ($c) {
				$methods = $c['methods'];
				$m = $methods[$method_name];
				if (!$m) {
					$name = $c['v']->name;
					print_error("Call to undefined method {$name}::{$method_name}()", $file, $call->getAttribute('startLine', -1));
				}
			} else {
				$methods = get_class_methods($var['t']);
				$n = 0;
				foreach ($methods as $m) {
					if ($method_name == strtolower($m)) {
						$n++;
						break;
					}
				}
				if (!$n) {
					//调用方法不存在
					$name = $c['v']->name;
					print_error("Call to undefined method {$name}::{$method_name}()", $file, $call->getAttribute('startLine', -1));
				}
			}
		}
		$vars[$var_name]['c']++;
	}
}

function parse_class_definition($class, &$classes, $file, $ns = '') {
	$name = strtolower($ns . $class->name);
	if ($classes[$name] || class_exists($name)) {
		//类重复定义
		print_error("Cannot redeclare class {$name}", $file, $class->getAttribute('startLine', -1));
	}
	$methods = array();
	$properties = array();

	foreach ($class->stmts as $stmt) {
		$c = get_class($stmt);
		switch ($c) {
			case 'PhpParser\Node\Stmt\ClassMethod':
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

function parse_class($class, &$classes, $functions, $file, $ns = '') {
	$name = strtolower($ns . $class->name);
	foreach ($class->stmts as $stmt) {
		$c = get_class($stmt);
		switch ($c) {
			case 'PhpParser\Node\Stmt\ClassMethod':
				parse_method($stmt, $classes, $functions, $file, $classes[$name]);
				break;

		}
	}
}

function parse_method($method, $classes, $functions, $file, $class) {
	$vars = array();
	foreach ($method->params as $param) {
		$name = $param->name;
		$vars[$name]['v'] = $param;
		$vars[$name]['c']++;
	}
	parse_stmts($method->stmts, $vars, $classes, $functions, $file, '', $class);
	check_unused_variable($vars);
}

function print_error($error, $file, $line) {
	printf("error:  %s in %s on line %d\n", $error, $file, $line);
}
