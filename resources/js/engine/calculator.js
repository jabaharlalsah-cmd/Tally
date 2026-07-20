/* =========================================================================
   ZeroBook keyboard engine — safe arithmetic evaluator for the calculator pane
   No eval(). Tokenise -> shunting-yard -> evaluate RPN. Supports
   + - * / %, parentheses, decimals, unary minus, and percentages (e.g. 200*5%).
   ========================================================================= */

function tokenize(src) {
    const tokens = [];
    let i = 0;
    const s = src.replace(/\s+/g, '');
    while (i < s.length) {
        const ch = s[i];
        if (/[0-9.]/.test(ch)) {
            let num = '';
            while (i < s.length && /[0-9.]/.test(s[i])) num += s[i++];
            if ((num.match(/\./g) || []).length > 1) throw new Error('Malformed number');
            tokens.push({ t: 'num', v: parseFloat(num) });
            continue;
        }
        if ('+-*/%()'.includes(ch)) {
            tokens.push({ t: 'op', v: ch });
            i++;
            continue;
        }
        throw new Error('Unexpected "' + ch + '"');
    }
    return tokens;
}

const PREC = { '+': 1, '-': 1, '*': 2, '/': 2, '%': 2, 'u-': 3 };

function toRpn(tokens) {
    const out = [];
    const ops = [];
    let prev = null;
    for (const tk of tokens) {
        if (tk.t === 'num') {
            out.push(tk);
        } else if (tk.v === '(') {
            ops.push(tk);
        } else if (tk.v === ')') {
            while (ops.length && ops[ops.length - 1].v !== '(') out.push(ops.pop());
            if (!ops.length) throw new Error('Mismatched )');
            ops.pop();
        } else {
            // operator — detect unary minus
            let op = tk.v;
            const unary = op === '-' && (prev === null || (prev.t === 'op' && prev.v !== ')'));
            if (unary) op = 'u-';
            while (
                ops.length &&
                ops[ops.length - 1].v !== '(' &&
                PREC[ops[ops.length - 1].v] >= PREC[op]
            ) {
                out.push(ops.pop());
            }
            ops.push({ t: 'op', v: op });
        }
        prev = tk;
    }
    while (ops.length) {
        const o = ops.pop();
        if (o.v === '(') throw new Error('Mismatched (');
        out.push(o);
    }
    return out;
}

function evalRpn(rpn) {
    const st = [];
    for (const tk of rpn) {
        if (tk.t === 'num') {
            st.push(tk.v);
        } else if (tk.v === 'u-') {
            if (!st.length) throw new Error('Bad expression');
            st.push(-st.pop());
        } else if (tk.v === '%') {
            if (st.length < 1) throw new Error('Bad expression');
            // Standalone percent: turn top of stack into a fraction of the
            // preceding value if present (a * b%  => a * (b/100)).
            const b = st.pop();
            if (st.length) {
                const a = st.pop();
                st.push(a * (b / 100));
            } else {
                st.push(b / 100);
            }
        } else {
            if (st.length < 2) throw new Error('Bad expression');
            const b = st.pop();
            const a = st.pop();
            switch (tk.v) {
                case '+':
                    st.push(a + b);
                    break;
                case '-':
                    st.push(a - b);
                    break;
                case '*':
                    st.push(a * b);
                    break;
                case '/':
                    if (b === 0) throw new Error('Divide by zero');
                    st.push(a / b);
                    break;
                default:
                    throw new Error('Unknown op');
            }
        }
    }
    if (st.length !== 1) throw new Error('Bad expression');
    return st[0];
}

/**
 * Evaluate an arithmetic expression string.
 * @returns {{ ok: true, value: number } | { ok: false, error: string }}
 */
export function calculate(expr) {
    try {
        const trimmed = (expr || '').trim();
        if (!trimmed) return { ok: false, error: '' };
        const result = evalRpn(toRpn(tokenize(trimmed)));
        if (!isFinite(result)) return { ok: false, error: 'Not a finite number' };
        // Trim floating error, keep up to 6 decimals.
        const rounded = Math.round((result + Number.EPSILON) * 1e6) / 1e6;
        return { ok: true, value: rounded };
    } catch (err) {
        return { ok: false, error: err.message || 'Invalid expression' };
    }
}
