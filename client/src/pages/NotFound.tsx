import { Button } from "@/components/ui/button";
import { AlertCircle, Home } from "lucide-react";
import { useEffect } from "react";
import { useLocation } from "wouter";

// Hosting answers this path with HTTP 404 while still serving the app.
const NOT_FOUND_PATH = "/404";

// Render for unknown routes and for items the server confirmed missing (e.g. `/page/:id`).
// The redirect is irreversible: never render while loading, on other errors, or before auth resolves.
export default function NotFound() {
  const [, setLocation] = useLocation();

  useEffect(() => {
    const robots = document.createElement("meta");
    robots.name = "robots";
    robots.content = "noindex";
    document.head.appendChild(robots);

    // Full-page navigation: a client-side route change sends no request, so no 404 status.
    if (window.location.pathname.replace(/\/$/, "") !== NOT_FOUND_PATH) {
      window.location.replace(NOT_FOUND_PATH);
    }

    return () => robots.remove();
  }, []);

  const handleGoHome = () => {
    setLocation("/");
  };

  return (
    <div className="nf-shell">
      <div className="nf-card">
        <div className="nf-badge"><AlertCircle size={22} /></div>
        <div className="nf-code">404</div>
        <h1 className="nf-title">Page not found</h1>
        <p className="nf-copy">
          That route doesn't exist in this workspace. It may have been moved, renamed or deleted.
        </p>
        <Button className="nf-button" onClick={handleGoHome}>
          <Home size={15} /> Back to dashboard
        </Button>
      </div>
    </div>
  );
}
