import { FcGoogle } from "react-icons/fc";
import { FaGithub, FaFacebookF } from "react-icons/fa";

const providers = [
    { id: "google", name: "Google", Icon: FcGoogle },
    { id: "github", name: "GitHub", Icon: FaGithub },
    { id: "facebook", name: "Facebook", Icon: FaFacebookF },
];

function getOAuthUrl(provider) {
    const apiBaseUrl =
        import.meta.env.VITE_API_BASE_URL || "http://localhost:8000/api";

    const backendOrigin = new URL(apiBaseUrl).origin;

    return `${backendOrigin}/auth/${provider}/redirect`;
}

function SocialLoginButtons() {
    return (
        <div className="mt-4">
            <div className="d-flex align-items-center gap-3 mb-3">
                <hr className="flex-grow-1" />
                <span className="text-body-secondary small text-nowrap">
                    Or continue with
                </span>
                <hr className="flex-grow-1" />
            </div>

            <div className="d-grid gap-2">
                {providers.map(({ id, name, Icon }) => (
                    <a
                        key={id}
                        href={getOAuthUrl(id)}
                        className="btn btn-outline-secondary w-100"
                    >
                        <span className="d-inline-flex align-items-center gap-2">
                            <Icon
                                size={20}
                                color={
                                    id === "facebook" ? "#1877F2" : undefined
                                }
                                aria-hidden="true"
                            />
                            Continue with {name}
                        </span>
                    </a>
                ))}
            </div>
        </div>
    );
}

export default SocialLoginButtons;
