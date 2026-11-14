import { trpc } from "@/lib/trpc";
import { useCallback } from "react";

export function useAuth() {
  const utils = trpc.useUtils();
  const meQuery = trpc.auth.me.useQuery(undefined, { refetchOnWindowFocus: false });
  const logoutMutation = trpc.auth.logout.useMutation();

  const logout = useCallback(async () => {
    try {
      await logoutMutation.mutateAsync(undefined);
    } finally {
      utils.experiments.list.remove();
      utils.experiments.trend.remove();
      utils.auth.me.setData(undefined, null);
    }
  }, [logoutMutation, utils]);

  return {
    user: meQuery.data ?? null,
    loading: meQuery.isLoading || logoutMutation.isPending,
    error: meQuery.error ?? logoutMutation.error ?? null,
    isAuthenticated: Boolean(meQuery.data),
    refresh: () => meQuery.refetch(),
    logout,
  };
}
