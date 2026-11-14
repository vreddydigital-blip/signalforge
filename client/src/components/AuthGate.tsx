import { Button } from "@/components/ui/button";
import { trpc } from "@/lib/trpc";
import { FlaskConical } from "lucide-react";
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

  const inputClass = "w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm outline-none focus:border-orange-500";
  const onEnter = (event: React.KeyboardEvent) => { if (event.key === "Enter") submit(event); };

  return (
    <div className="min-h-screen grid place-items-center bg-neutral-50 p-4">
      <div className="w-full max-w-sm rounded-2xl border border-neutral-200 bg-white p-7 shadow-sm">
        <div className="mb-6 flex items-center gap-3">
          <div className="grid h-10 w-10 place-items-center rounded-xl bg-orange-500 text-white"><FlaskConical size={20} /></div>
          <div>
            <div className="text-lg font-bold leading-tight">SignalForge</div>
            <div className="text-xs text-neutral-500">Experiment with intent</div>
          </div>
        </div>
        <h1 className="mb-1 text-xl font-semibold">{mode === "login" ? "Welcome back" : "Create your workspace"}</h1>
        <p className="mb-5 text-sm text-neutral-500">{mode === "login" ? "Sign in to see your experiments." : "Start running A/B tests in minutes."}</p>
        <div className="space-y-3" onKeyDown={onEnter}>
          {mode === "register" && (
            <label className="block text-sm font-medium">Name
              <input className={`${inputClass} mt-1`} value={name} onChange={e => setName(e.target.value)} autoComplete="name" />
            </label>
          )}
          <label className="block text-sm font-medium">Email
            <input className={`${inputClass} mt-1`} type="email" value={email} onChange={e => setEmail(e.target.value)} autoComplete="email" />
          </label>
          <label className="block text-sm font-medium">Password
            <input className={`${inputClass} mt-1`} type="password" value={password} onChange={e => setPassword(e.target.value)} autoComplete={mode === "login" ? "current-password" : "new-password"} />
          </label>
          {error && <p className="text-sm text-red-600" role="alert">{error}</p>}
          <Button className="w-full" disabled={busy} onClick={submit}>{busy ? "Please wait…" : mode === "login" ? "Sign in" : "Create account"}</Button>
        </div>
        <p className="mt-5 text-center text-sm text-neutral-500">
          {mode === "login" ? "New here? " : "Already have an account? "}
          <button className="font-medium text-orange-600 hover:underline" onClick={() => { setMode(mode === "login" ? "register" : "login"); setError(""); }}>
            {mode === "login" ? "Create an account" : "Sign in"}
          </button>
        </p>
      </div>
    </div>
  );
}

export default function AuthGate({ children }: { children: ReactNode }) {
  const me = trpc.auth.me.useQuery(undefined, { retry: false, refetchOnWindowFocus: false });
  if (me.isLoading) {
    return <div className="min-h-screen grid place-items-center text-sm text-neutral-500">Loading…</div>;
  }
  if (!me.data) return <AuthForm />;
  return <>{children}</>;
}
