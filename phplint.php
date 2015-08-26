#!/usr/bin/php
<?php
require(__DIR__ . '/lib/PHP-Parser/lib/bootstrap.php');
error_reporting(E_ALL & ~E_NOTICE);

class VariableNodeVisitor extends PhpParser\NodeVisitorAbstract {
	protected $vars;

	public function __construct(&$vars) {
		$this->vars = &$vars;
	}

	public function leaveNode(PhpParser\Node $node) {
		$n = $node->name;
		if ($n != 'this') {
			parse_variable($node, $this->vars);
		}
	}
}

$vars = array();
$classes = array();
$functions = array();

parse_file($argv[1], $vars, $classes, $functions);

function check_unused_variable($vars, $file) {
	$super_global = array(
			'GLOBALS' => 1, 
			'_SERVER' => 1, 
			'_GET' => 1, 
			'_POST' => 1, 
			'_FILES' => 1, 
			'_COOKIE' => 1, 
			'_SESSION' => 1, 
			'_REQUEST' => 1, 
			'_ENV' => 1, 
			'argv' => 1);

	foreach ($vars as $n => $var) {
		if ($super_global[$n]) {
			continue;
		}
		if ($var['c'] <= 1) {
			print_error("Unused variable \${$n}", $file, $var['v']->getAttribute('startLine', -1));
		}
	}
}

function parse_file($file, &$vars, &$classes, &$functions) {
	$code = file_get_contents($file);
	$parser = new PhpParser\Parser(new PhpParser\Lexer());
	$stmts = $parser->parse($code);
	parse_stmts($stmts, $vars, $classes, $functions, $file);
	check_unused_variable($vars, $file);
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
				parse_function($stmt, $classes, $functions, $file);
				break;
			case 'PhpParser\Node\Stmt\Class_':
				parse_class($stmt, $classes, $functions, $file, $ns);
				break;
			case 'PhpParser\Node\Expr\Include_':
				parse_include($stmt, $file);
				break;
			case 'PhpParser\Node\Stmt\For_':
				parse_stmts($stmt->init, $vars, $classes, $functions, $file, $ns, $class);
				parse_stmts($stmt->stmts, $vars, $classes, $functions, $file, $ns, $class);
				break;
			case 'PhpParser\Node\Stmt\Foreach_':
				parse_exprs(array($stmt->expr), $vars, $file, $class);
				if ($stmt->keyVar) {
					parse_variable($stmt->keyVar, $vars, $file);
				}
				parse_variable($stmt->valueVar, $vars, $file);
				parse_stmts($stmt->stmts, $vars, $classes, $functions, $file, $ns, $class);
				break;
			case 'PhpParser\Node\Stmt\While_':
				parse_exprs(array($stmt->cond), $vars, $file, $class);
				parse_stmts($stmt->stmts, $vars, $classes, $functions, $file, $ns, $class);
				break;
			case 'PhpParser\Node\Stmt\If_':
				parse_exprs(array($stmt->cond), $vars, $file, $class);
				parse_stmts($stmt->stmts, $vars, $classes, $functions, $file, $ns, $class);
				if ($stmt->else) {
					parse_stmts($stmt->else->stmts, $vars, $classes, $functions, $file, $ns, $class);
				}
				break;
			case 'PhpParser\Node\Stmt\Switch_':
				parse_exprs(array($stmt->cond), $vars, $file, $class);
				foreach ($stmt->cases as $case) {
					parse_stmts($case->stmts, $vars, $classes, $functions, $file, $ns, $class);
				}
				break;
			case 'PhpParser\Node\Stmt\Use_':
				break;
			case 'PhpParser\Node\Stmt\Echo_':
				parse_exprs($stmt->exprs, $vars, $file, $class);
				break;
			case 'PhpParser\Node\Expr\Assign':
			case 'PhpParser\Node\Expr\AssignRef':
				parse_assign($stmt, $vars, $classes, $functions, $file, $ns, $class);
				break;
			case 'PhpParser\Node\Expr\FuncCall':
				parse_call($stmt, $vars, $functions, $file, $ns);
				break;
			case 'PhpParser\Node\Expr\StaticCall':
				parse_static_call($stmt, $vars, $classes, $file, $ns);
				break;
			case 'PhpParser\Node\Expr\MethodCall':
				parse_method_call($stmt, $vars, $classes, $file, $class);
				break;
			case 'PhpParser\Node\Stmt\Return_':
				if ($stmt->expr) {
					parse_exprs(array($stmt->expr), $vars, $file, $class);
				}
				break;
			case 'PhpParser\Node\Stmt\TryCatch':
				parse_stmts($stmt->stmts, $vars, $classes, $functions, $file, $ns, $class);
				break;
			case 'PhpParser\Node\Expr\AssignOp\Concat':
			case 'PhpParser\Node\Expr\AssignOp\Plus':
			case 'PhpParser\Node\Expr\AssignOp\Minus':
			case 'PhpParser\Node\Expr\AssignOp\Mul':
			case 'PhpParser\Node\Expr\AssignOp\Div':
				parse_exprs(array($stmt->var), $vars, $file, $class);
				parse_exprs(array($stmt->expr), $vars, $file, $class);
				break;	
			case 'PhpParser\Node\Expr\PostInc':
			case 'PhpParser\Node\Expr\PostDec':
				parse_exprs(array($stmt->var), $vars, $file, $class);
				break;
		}
	}
}

function parse_include() {
}

function parse_exprs($exprs, &$vars, $file, $class = null) {
	$traverser = new PhpParser\NodeTraverser();
	$traverser->addVisitor(new VariableNodeVisitor($vars));	
	foreach ($exprs as $expr) {
		$c = get_class($expr);
		switch ($c) {
			case 'PhpParser\Node\Expr\PropertyFetch':
				if ($expr->var->name == 'this' && $class) {
					$properties = $class['properties'];
					$name = $expr->name;
					if (!$properties[$name]) {
						print_error("Use undefined property \$this->{$name}", $file, $expr->getAttribute('startLine', -1));
					}
				}
				break;
			default:
				$traverser->traverse(array($expr));
				
		}
	}
}

function parse_assign($assign, &$vars, $classes, $functions, $file, $ns = '', $class = null) {
	$var = $assign->var;
	parse_variable($var, $vars, $file);
	$name = $var->name;
	$c = get_class($assign->expr);
	switch ($c) {
		case 'PhpParser\Node\Expr\New_':
			foreach ($assign->expr->args as $arg) {
				parse_variable($arg->value, $vars, $file);
			}
			switch (get_class($assign->expr->class)) {
				case 'PhpParser\Node\Name\FullyQualified':
					$t = strtolower(implode('\\', $assign->expr->class->parts));
					break;
				case 'PhpParser\Node\Name':
					$t = $ns . strtolower(implode('\\', $assign->expr->class->parts));
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
			break;
		default:
			parse_exprs(array($assign->expr), $vars, $file, $class);
			if ($vars[$name]) {
				$vars[$name]['t'] = '';
			}
			break;
	}
}

function parse_function_definition($fun, &$functions, $file, $ns = '') {
	$name = strtolower($ns . $fun->name);
	if ($functions[$name] || function_exists($name)) {
		//方法重复定义
		print_error("Cannot redeclare {$name}()", $file, $fun->getAttribute('startLine', -1));
	}
	$functions[$name] = $fun;
}


function parse_function($fun, $classes, $functions, $file) {
	$vars = array();
	
	foreach ($fun->params as $param) {
		parse_variable($param, $vars);
	}
	
	parse_stmts($fun->stmts, $vars, $classes, $functions, $file);
	check_unused_variable($vars, $file);
}

function parse_variable($var, &$vars) {
	$c = get_class($var);
	switch ($c) {
		case 'PhpParser\Node\Expr\ArrayDimFetch':
			parse_variable($var->var, $vars);
			parse_variable($var->dim, $vars);
			break;
		case 'PhpParser\Node\Param':
		case 'PhpParser\Node\Expr\Variable':
			$n = $var->name;
			if (!$vars[$n]) {
				$vars[$n]['v'] = $var;
			}
			$vars[$n]['c']++;
			break;
	}
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
				parse_variable($arg->value, $vars);
				break;
			default:
				parse_exprs(array($arg), $vars, $file);
				break;
		}
	}
}

function parse_static_call($call, &$vars, $classes, $file, $ns) {
	$class_name = $ns . strtolower(implode('\\',$call->class->parts));
	$func_name = strtolower($call->name);
	foreach ($call->args as $arg) {
		$c = get_class($arg->value);
		switch ($c) {
			case 'PhpParser\Node\Expr\Variable':
				parse_variable($arg->value, $vars);
				break;
			default:
				parse_exprs(array($arg), $vars, $file);
				break;
		}
	}

	$class = $classes[$class_name];
	if ($class) {
		$methods = $class['methods'];
		$m = $methods[$func_name];
		if (!($m instanceof PhpParser\Node\Stmt\ClassMethod)) {
			print_error("Call to undefined method {$class_name}::{$func_name}()", $file, $call->getAttribute('startLine', -1));
		}
	} else {
		if (class_exists($class_name)) {
			$methods = get_class_methods($class_name);
			$n = 0;
			foreach ($methods as $m) {
				if ($func_name == strtolower($m)) {
					$n++;
					break;
				}
			}
			if (!$n) {
				//调用方法不存在
				print_error("Call to undefined method {$class_name}::{$func_name}()", $file, $call->getAttribute('startLine', -1));
			}
		} else {
			//调用未知类型对象的方法
		}
	}

}

function parse_method_call($call, &$vars, $classes, $file, $class = null) {
	$var_name = $call->var->name;
	$method_name = strtolower($call->name);
	$var = $vars[$var_name];

	foreach ($call->args as $arg) {
		$c = get_class($arg->value);
		switch ($c) {
			case 'PhpParser\Node\Expr\Variable':
				parse_variable($arg->value, $vars);
				break;
			case 'PhpParser\Node\Expr\New_':
				parse_exprs(array($arg->value), $vars, $file, $class);
				break;
		}
	}

	if ($var_name == 'this' && $class) {
		$methods = $class['methods'];
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
	check_unused_variable($vars, $file);
}

function print_error($error, $file, $line) {
	printf("error:  %s in %s on line %d\n", $error, $file, $line);
}
