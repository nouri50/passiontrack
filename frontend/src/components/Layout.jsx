import Header from "./Header";
import Footer from "./Footer";
import ToastContainer from "./ToastContainer";
import "../styles/Layout.css";

function Layout({ children }) {
  return (
    <div className="layout">
      <Header />
      <main className="layout-content">{children}</main>
      <Footer />
      <ToastContainer />
    </div>
  );
}

export default Layout;
