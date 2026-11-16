import { Button } from "@/components/ui/button";
import { trpc } from "@/lib/trpc";
import { FlaskConical, ArrowRight, Loader2 } from "lucide-react";
import { useState, type ReactNode } from "react";

function AuthForm() {
  const utils = trpc.useUtils();
  const [mode, setMode] = useState<"login" | "register">("login");
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");

  const onSuccess = () => { utils.auth.me.invalidate(); };
  const onError = (err: { message: string }) => { setError(err.message); };
  const login = trpc.auth.login.useMutation({ onSuccess, onError });
  const register = trpc.auth.register.useMutation({ onSuccess, onError });
  const busy = login.isPending || register.isPending;

  const submit = (event: React.MouseEvent | React.KeyboardEvent) => {
    event.preventDefault();
    setError("");
    if (!/^\S+@\S+\.\S+$/.test(email)) return setError("Enter a valid email address.");
    if (mode === "register") {
      if (!name.trim()) return setError("Enter your name.");
      if (password.length < 8) return setError("Password must be at least 8 characters.");
      register.mutate({ name: name.trim(), email, password });
    } else {
      if (!password) return setError("Enter your password.");
      login.mutate({ email, password });
    }
  };

  const onEnter = (event: React.KeyboardEvent) => { if (event.key === "Enter") submit(event); };

  return (
    <div className="auth-shell">
      <div className="auth-grid-lines" aria-hidden="true" />

      <div className="auth-panel">
        <div className="auth-brand">
          <div className="brand-mark"><FlaskConical size={19} strokeWidth={2.4} /></div>
          <div>
            <div className="brand-name">SignalForge</div>
            <div className="brand-tagline">Experiment with intent</div>
          </div>
        </div>

        <span className="auth-kicker">
          <span className="live-pulse" />
          {mode === "login" ? "Secure console access" : "New workspace"}
        </span>

        <h1 className="auth-title">{mode === "login" ? "Welcome back" : "Create your workspace"}</h1>
        <p className="auth-sub">
          {mode === "login" ? "Sign in to see your experiments." : "Start running A/B tests in minutes."}
        </p>

        <div className="auth-fields" onKeyDown={onEnter}>
          {mode === "register" && (
            <label className="auth-field">Name
              <input value={name} onChange={e => setName(e.target.value)} autoComplete="name" placeholder="Ada Lovelace" />
            </label>
          )}
          <label className="auth-field">Email
            <input type="email" value={email} onChange={e => setEmail(e.target.value)} autoComplete="email" placeholder="you@company.com" />
          </label>
          <label className="auth-field">Password
            <input type="password" value={password} onChange={e => setPassword(e.target.value)} autoComplete={mode === "login" ? "current-password" : "new-password"} placeholder="••••••••" />
          </label>

          {error && <p className="auth-error" role="alert">{error}</p>}

          <Button className="auth-submit" disabled={busy} onClick={submit}>
            {busy ? <><Loader2 size={15} className="spin" /> Please wait…</> : <>{mode === "login" ? "Sign in" : "Create account"} <ArrowRight size={15} /></>}
          </Button>
        </div>

        <p className="auth-switch">
          {mode === "login" ? "New here? " : "Already have an account? "}
          <button onClick={() => { setMode(mode === "login" ? "register" : "login"); setError(""); }}>
            {mode === "login" ? "Create an account" : "Sign in"}
          </button>
        </p>
      </div>

      <div className="auth-aside">
        <span className="auth-kicker"><span className="live-pulse" /> SignalForge console</span>
        <h2 className="auth-aside-title">Every test, measured<br />to the last signal.</h2>
        <p className="auth-aside-copy">
          Guided experiment briefs, Bayesian readouts and segment-level lift — in one instrument panel.
        </p>
        <ul className="auth-aside-list">
          <li>Bayesian probability, not p-hacks</li>
          <li>Segment-level lift you can act on</li>
          <li>One-line tracking snippet</li>
          <li>Automatic winner detection</li>
        </ul>
      </div>
    </div>
  );
}

export default function AuthGate({ children }: { children: ReactNode }) {
  const me = trpc.auth.me.useQuery(undefined, { retry: false, refetchOnWindowFocus: false });
  if (me.isLoading) {
    return (
      <div className="auth-loading">
        <Loader2 size={18} className="spin" />
        <span>Loading console…</span>
      </div>
    );
  }
  if (!me.data) return <AuthForm />;
  return <>{children}</>;
}
