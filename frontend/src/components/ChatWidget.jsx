import { useState, useRef, useEffect } from "react";
import { useTranslation } from "react-i18next";
import useAuthStore from "../stores/authStore";
import useToastStore from "../stores/toastStore";
import { sendChatMessage } from "../services/chatService";
import { getApiErrorMessage } from "../utils/apiError";
import "../styles/ChatWidget.css";

const SUGGESTION_KEYS = [
  "chat.suggestion1",
  "chat.suggestion2",
  "chat.suggestion3",
  "chat.suggestion4",
];

function ChatWidget() {
  const { t } = useTranslation();
  const { isAuthenticated } = useAuthStore();
  const addToast = useToastStore((state) => state.addToast);

  const [isOpen, setIsOpen] = useState(false);
  const [messages, setMessages] = useState([]);
  const [input, setInput] = useState("");
  const [isSending, setIsSending] = useState(false);
  const messagesEndRef = useRef(null);

  useEffect(() => {
    if (isOpen) {
      messagesEndRef.current?.scrollIntoView({ behavior: "smooth" });
    }
  }, [messages, isOpen]);

  if (!isAuthenticated) {
    return null;
  }

  const sendMessage = async (text) => {
    const trimmed = text.trim();
    if (!trimmed || isSending) return;

    const historyForRequest = messages;
    const userMessage = { role: "user", content: trimmed };
    setMessages((prev) => [...prev, userMessage]);
    setInput("");
    setIsSending(true);

    try {
      const { reply } = await sendChatMessage(trimmed, historyForRequest);
      setMessages((prev) => [...prev, { role: "assistant", content: reply }]);
    } catch (err) {
      addToast(getApiErrorMessage(err, t, "chat.errorGeneric"), "error");
      // Retire le message utilisateur en cas d'échec, pour ne pas laisser
      // la conversation avec une question sans réponse associée.
      setMessages((prev) => prev.filter((m) => m !== userMessage));
    } finally {
      setIsSending(false);
    }
  };

  const handleSend = (e) => {
    e.preventDefault();
    sendMessage(input);
  };

  const handleSuggestionClick = (question) => {
    sendMessage(question);
  };

  return (
    <>
      <button
        type="button"
        className="chat-widget-toggle"
        onClick={() => setIsOpen((open) => !open)}
        aria-label={isOpen ? t("chat.close") : t("chat.open")}
      >
        {isOpen ? "✕" : "💬"}
      </button>

      {isOpen && (
        <div className="chat-widget-panel">
          <div className="chat-widget-header">
            <span>🤖 {t("chat.title")}</span>
          </div>

          <div className="chat-widget-messages">
            {messages.length === 0 && (
              <div className="chat-widget-suggestions">
                <p className="chat-widget-empty">{t("chat.emptyState")}</p>
                {SUGGESTION_KEYS.map((key) => (
                  <button
                    key={key}
                    type="button"
                    className="chat-widget-suggestion"
                    onClick={() => handleSuggestionClick(t(key))}
                    disabled={isSending}
                  >
                    {t(key)}
                  </button>
                ))}
              </div>
            )}
            {messages.map((msg, i) => (
              <div
                key={i}
                className={`chat-widget-message chat-widget-message--${msg.role}`}
              >
                {msg.content}
              </div>
            ))}
            {isSending && (
              <div className="chat-widget-message chat-widget-message--assistant chat-widget-message--pending">
                {t("chat.thinking")}
              </div>
            )}
            <div ref={messagesEndRef} />
          </div>

          <form onSubmit={handleSend} className="chat-widget-form">
            <input
              type="text"
              value={input}
              onChange={(e) => setInput(e.target.value)}
              placeholder={t("chat.inputPlaceholder")}
              disabled={isSending}
            />
            <button type="submit" disabled={isSending || !input.trim()}>
              {t("chat.send")}
            </button>
          </form>
        </div>
      )}
    </>
  );
}

export default ChatWidget;
