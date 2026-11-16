import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { createRoot } from "react-dom/client";
import App from "./App";
import { ApiError } from "./lib/trpc";
import "./index.css";
import "./prediction-suite.css";

const queryClient = new QueryClient();

const handleError = (error: unknown) => {
  // Session expired: refetch auth.me, which returns null and shows the sign-in form.
  if (error instanceof ApiError && error.status === 401 && error.data.code === "UNAUTHORIZED" && error.message.includes("10001")) {
    queryClient.invalidateQueries({ queryKey: ["auth.me"] });
  }
};

queryClient.getQueryCache().subscribe(event => {
  if (event.type === "updated" && event.action.type === "error") handleError(event.query.state.error);
});
queryClient.getMutationCache().subscribe(event => {
  if (event.type === "updated" && event.action.type === "error") handleError(event.mutation.state.error);
});

createRoot(document.getElementById("root")!).render(
  <QueryClientProvider client={queryClient}>
    <App />
  </QueryClientProvider>
);
