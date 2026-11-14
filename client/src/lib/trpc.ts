// Lightweight client for the PHP API (infinityfree/api). It keeps the same call shape the
// dashboard already used (trpc.experiments.list.useQuery(), ...useMutation(), trpc.useUtils()).
import {
  useMutation,
  useQuery,
  useQueryClient,
  type QueryClient,
  type UseMutationResult,
  type UseQueryResult,
} from "@tanstack/react-query";

export class ApiError extends Error {
  data: { code: string };
  status: number;
  constructor(message: string, code: string, status: number) {
    super(message);
    this.name = "ApiError";
    this.data = { code };
    this.status = status;
  }
}

const ENDPOINT = "api/index.php";

async function call(route: string, method: "GET" | "POST", input?: unknown) {
  const query = method === "GET" && input !== undefined ? `&input=${encodeURIComponent(JSON.stringify(input))}` : "";
  // "?i=1" skips InfinityFree's browser-check page for background requests.
  const response = await fetch(`${ENDPOINT}?i=1&route=${route}${query}`, {
    method,
    credentials: "same-origin",
    headers: method === "POST" ? { "Content-Type": "application/json", "X-Requested-With": "signalforge" } : undefined,
    body: method === "POST" ? JSON.stringify(input ?? {}) : undefined,
  });
  let payload: any;
  try {
    payload = await response.json();
  } catch {
    throw new ApiError("The server did not return valid data. Check that the api folder is uploaded and PHP is enabled.", "INTERNAL_SERVER_ERROR", response.status);
  }
  if (!response.ok || payload?.error) {
    throw new ApiError(payload?.error?.message ?? "Request failed", payload?.error?.code ?? "INTERNAL_SERVER_ERROR", response.status);
  }
  return payload.data;
}

export type Plan = "Free" | "Growth" | "Scale";
export type PublicUser = { id: number; name: string | null; email: string | null; role: string; plan: Plan; projectKey: string | null };
export type TrendPoint = { day: string; control: number; variant: number };
export type WorkspaceSettings = { workspaceName: string; timezone: string; defaultGoal: string; emailUpdates: boolean; weeklyDigest: boolean; revenuePerConversion: number; monthlyVisitors: number };
export type TrackedEvent = { name: string; type: string; selector: string; status: string };
export type TrackingHealth = { scriptDetected: boolean; lastEventTs: number | null; lastEventAgo: string | null; eventsLast24h: number; visitorRows: number; conversions: number; mappedOutcomes: number; events: TrackedEvent[] };
export type SegmentRow = { segment: string; lift: number; signal: string; visitors: number };

export type AgentMeta = { key: string; name: string; role: string; icon: string; accent: string };
export type AgentMetric = { label: string; value: string; agent?: string };
export type AgentOutput = {
  headline: string;
  metrics: { label: string; value: string }[];
  findings: string[];
  recommendation: string;
  tone: "good" | "warn" | "bad" | "info";
};
export type AgentTask = {
  id: number;
  agentKey: string;
  agentName: string;
  agentRole: string;
  icon: string;
  accent: string;
  title: string;
  status: "queued" | "running" | "done" | "failed";
  durationMs: number | null;
  output: AgentOutput | null;
  error: string | null;
};
export type AgentRunSummary = {
  agentsDone: number;
  agentsFailed: number;
  metrics: AgentMetric[];
  findings: { text: string; agent: string; accent: string }[];
  recommendations: { text: string; agent: string; tone: string }[];
  lead: { text: string; agent: string; tone: string } | null;
  tone: string;
};
export type AgentRun = {
  id: number;
  objective: string;
  status: "running" | "completed" | "failed" | "cancelled";
  createdAt: string;
  progress: number;
  tasks: AgentTask[];
  summary: AgentRunSummary;
};
export type AgentRunListItem = { id: number; objective: string; status: string; createdAt: string; updatedAt: string };

type QueryOptions = { enabled?: boolean; refetchInterval?: number; refetchOnWindowFocus?: boolean; retry?: boolean };
type Q<T> = (input?: unknown, options?: QueryOptions) => UseQueryResult<T, ApiError>;
type MutationOptions<T> = { onSuccess?: (data: T) => void; onError?: (error: ApiError) => void };
type M<T> = (options?: MutationOptions<T>) => UseMutationResult<T, ApiError, any>;
type Utils = { invalidate(): void; remove(): void; setData(input: unknown, data: unknown): void };

interface Api {
  useUtils(): { auth: { me: Utils }; experiments: { list: Utils; trend: Utils; segments: Utils }; settings: { get: Utils }; tracking: { health: Utils }; orchestrator: { runs: Utils; state: Utils } };
  auth: {
    me: { useQuery: Q<PublicUser | null> };
    login: { useMutation: M<PublicUser> };
    register: { useMutation: M<PublicUser> };
    logout: { useMutation: M<{ success: boolean }> };
  };
  account: { setPlan: { useMutation: M<{ plan: Plan }> } };
  settings: {
    get: { useQuery: Q<WorkspaceSettings> };
    save: { useMutation: M<WorkspaceSettings> };
  };
  tracking: { health: { useQuery: Q<TrackingHealth> } };
  orchestrator: {
    agents: { useQuery: Q<AgentMeta[]> };
    runs: { useQuery: Q<AgentRunListItem[]> };
    state: { useQuery: Q<AgentRun> };
    start: { useMutation: M<AgentRun> };
    tick: { useMutation: M<AgentRun> };
    cancel: { useMutation: M<AgentRun> };
  };
  experiments: {
    list: { useQuery: Q<any[]> };
    trend: { useQuery: Q<TrendPoint[]> };
    segments: { useQuery: Q<SegmentRow[]> };
    create: { useMutation: M<{ id: number }> };
    setStatus: { useMutation: M<{ ok: boolean }> };
    remove: { useMutation: M<{ ok: boolean }> };
    updateVariantCode: { useMutation: M<{ ok: boolean }> };
  };
}

function hookNode(path: string[]): any {
  return new Proxy(function () {}, {
    get(_target, key: string) {
      const route = path.join(".");
      if (key === "useQuery") {
        return (input?: unknown, options?: QueryOptions) =>
          useQuery({ queryKey: [route, input ?? null], queryFn: () => call(route, "GET", input), retry: false, ...options });
      }
      if (key === "useMutation") {
        return (options?: MutationOptions<unknown>) =>
          useMutation({ mutationFn: (input: unknown) => call(route, "POST", input), ...options });
      }
      return hookNode([...path, key]);
    },
  });
}

function utilsNode(client: QueryClient, path: string[]): any {
  return new Proxy(function () {}, {
    get(_target, key: string) {
      const route = path.join(".");
      if (key === "invalidate") return () => client.invalidateQueries({ queryKey: [route] });
      if (key === "remove") return () => client.removeQueries({ queryKey: [route] });
      if (key === "setData") return (input: unknown, data: unknown) => client.setQueryData([route, input ?? null], data);
      return utilsNode(client, [...path, key]);
    },
  });
}

export const trpc = new Proxy({} as Api, {
  get(_target, key: string) {
    if (key === "useUtils") {
      return () => utilsNode(useQueryClient(), []);
    }
    return hookNode([key]);
  },
}) as Api;
