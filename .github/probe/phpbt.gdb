set pagination off
set print elements 200
define phpframe
  set $f = $arg0->func
  if $f
    if $f->common.scope
      printf "%s::", (char *) $f->common.scope->name->val
    end
    if $f->common.function_name
      printf "%s()", (char *) $f->common.function_name->val
    else
      printf "{main}"
    end
    if $f->type == 2
      if $arg1
        printf " %s:%d opcode=%d", (char *) $f->op_array.filename->val, $arg1->lineno, $arg1->opcode
      else
        printf " %s", (char *) $f->op_array.filename->val
      end
    end
  end
  printf "\n"
end
define phpbt
  set $eg = (zend_executor_globals *) &executor_globals
  printf "--- PHP stack from EG(current_execute_data)\n"
  set $ex = $eg->current_execute_data
  set $depth = 0
  while $ex && $depth < 80
    phpframe $ex $ex->opline
    set $ex = $ex->prev_execute_data
    set $depth = $depth + 1
  end
end
define phpbtregs
  printf "--- PHP frame from hybrid VM registers (r14=execute_data, r15=opline)\n"
  set $rex = (zend_execute_data *) $r14
  set $rop = (zend_op *) $r15
  phpframe $rex $rop
end
