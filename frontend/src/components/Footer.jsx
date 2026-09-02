import "../styles/Footer.css";

function Footer() {
  return (
    <footer className="footer">
      <span>© {new Date().getFullYear()} PassionTrack</span>
      <div className="footer-links">
        <a href="/help">Aide</a>
        <a href="/about">À propos</a>
      </div>
    </footer>
  );
}

export default Footer;
