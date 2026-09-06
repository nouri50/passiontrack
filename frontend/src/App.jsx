import { useEffect } from "react";
import { Routes, Route, Navigate } from "react-router-dom";
import useAuthStore from "./stores/authStore";
import useThemeStore from "./stores/themeStore";
import Layout from "./components/Layout";
import Login from "./pages/Login";
import Dashboard from "./pages/Dashboard";
import Register from "./pages/Register";
import Sessions from "./pages/Sessions";
import SessionForm from "./pages/SessionForm";
import SessionEdit from "./pages/SessionEdit";
import SessionDetail from "./pages/SessionDetail";
import Analytics from "./pages/Analytics";
import Profile from "./pages/Profile";
import Notifications from "./pages/Notifications";
import Categories from "./pages/Categories";

function App() {
  const { checkAuth, isAuthenticated, isLoading } = useAuthStore();
  const initTheme = useThemeStore((state) => state.initTheme);

  useEffect(() => {
    checkAuth();
  }, [checkAuth]);

  useEffect(() => {
    initTheme();
  }, [initTheme]);

  if (isLoading) {
    return <div>Chargement...</div>;
  }

  return (
    <Layout>
      <Routes>
        <Route
          path="/"
          element={
            <Navigate to={isAuthenticated ? "/dashboard" : "/login"} replace />
          }
        />

        <Route
          path="/login"
          element={
            isAuthenticated ? <Navigate to="/dashboard" replace /> : <Login />
          }
        />

        <Route
          path="/register"
          element={
            isAuthenticated ? (
              <Navigate to="/dashboard" replace />
            ) : (
              <Register />
            )
          }
        />

        <Route
          path="/dashboard"
          element={
            isAuthenticated ? <Dashboard /> : <Navigate to="/login" replace />
          }
        />

        <Route
          path="/sessions"
          element={
            isAuthenticated ? <Sessions /> : <Navigate to="/login" replace />
          }
        />

        <Route
          path="/sessions/new"
          element={
            isAuthenticated ? <SessionForm /> : <Navigate to="/login" replace />
          }
        />

        <Route
          path="/sessions/:id/edit"
          element={
            isAuthenticated ? <SessionEdit /> : <Navigate to="/login" replace />
          }
        />

        <Route
          path="/sessions/:id"
          element={
            isAuthenticated ? (
              <SessionDetail />
            ) : (
              <Navigate to="/login" replace />
            )
          }
        />

        <Route
          path="/analytics"
          element={
            isAuthenticated ? <Analytics /> : <Navigate to="/login" replace />
          }
        />

        <Route
          path="/profile"
          element={
            isAuthenticated ? <Profile /> : <Navigate to="/login" replace />
          }
        />

        <Route
          path="/notifications"
          element={
            isAuthenticated ? (
              <Notifications />
            ) : (
              <Navigate to="/login" replace />
            )
          }
        />

        <Route
          path="/categories"
          element={
            isAuthenticated ? <Categories /> : <Navigate to="/login" replace />
          }
        />

        <Route
          path="*"
          element={
            <Navigate to={isAuthenticated ? "/dashboard" : "/login"} replace />
          }
        />
      </Routes>
    </Layout>
  );
}

export default App;
