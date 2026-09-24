import Header from "./Header";
import Footer from "./Footer";
import ToastContainer from "./ToastContainer";
import ChatWidget from "./ChatWidget";
import "../styles/Layout.css";

function Layout({ children }) {
  return (
    <div className="layout">
      <Header />
      <main className="layout-content">{children}</main>
      <Footer />
      <ToastContainer />
      <ChatWidget />
    </div>
  );
}

export default Layout;
