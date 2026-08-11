import { useContext, useEffect, useState } from "react";
import { User, LogOut, AlertCircle, CheckCircle2 } from "lucide-react";
import UserLocationMap from "../Components/LeafletMap";
import hocLogo from "../assets/hoc_logo.png";
import AuthContext from "../Contexts/AuthContext";
import { useNavigate } from "react-router-dom";
import { showNotification } from "@mantine/notifications";

export default function Settings() {
  const url = import.meta.env.VITE_API_URL;
  const navigate = useNavigate();
  const { token, logOut, setUser, user } = useContext(AuthContext);

  const [isEditing, setIsEditing] = useState(false);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);

  const [location, setLocation] = useState({
    lat: 14.958753194320153,   // HOC default
    lng: 120.75846924744896,
    city: "",
    country: "",
    full: "",
  });

  const [formData, setFormData] = useState({
    name: "",
    first_name: "",
    last_name: "",
    phone_number: "",
    note: "",
    location: "",
    latitude: null,
    longitude: null,
  });

  // Update form when location changes
  useEffect(() => {
    setFormData((prev) => ({
      ...prev,
      location: location.full || "",
      latitude: location.lat || null,
      longitude: location.lng || null,
    }));
  }, [location]);

  // 🔹 Fetch user info
  const fetchUser = async () => {
    setLoading(true);
    setError(null);
    try {
      if (!token) throw new Error("Authentication token missing");

      const res = await fetch(`${url}/api/user`, {
        headers: {
          Accept: "application/json",
          Authorization: `Bearer ${token}`,
        },
      });

      if (!res.ok) {
        if (res.status === 401) {
          throw new Error("Unauthorized. Please log in again.");
        }
        throw new Error(`Failed to fetch user data (Status: ${res.status})`);
      }

      const data = await res.json();
      setUser(data);
      setFormData({
        name: data.name || "",
        first_name: data.first_name || "",
        last_name: data.last_name || "",
        phone_number: data.phone_number || "",
        note: data.note || "",
        location: data.location || "",
        latitude: data.latitude || null,
        longitude: data.longitude || null,
      });

      if (data.lat && data.lng) {
        setLocation({
          lat: data.lat,
          lng: data.lng,
          full: data.location || "",
        });
      }
    } catch (err) {
      console.error("Failed to fetch user:", err);
      setError(err.message);
      showNotification({
        title: "Error",
        message: err.message || "Failed to load user data.",
        color: "red",
        icon: <AlertCircle />,
      });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchUser();
  }, []);

  // 🔹 Handle input changes
  const handleChange = (e) => {
    const { name, value } = e.target;
    setFormData((prev) => ({ ...prev, [name]: value }));
  };

  // 🔹 Save handler
  const handleSave = async () => {
    setSaving(true);
    try {
      const res = await fetch(`${url}/api/user/update`, {
        method: "PUT",
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify(formData),
      });

      if (!res.ok) {
        throw new Error(`Failed to update user info (Status: ${res.status})`);
      }

      const updated = await res.json();
      setUser(updated.user);
      setIsEditing(false);

      showNotification({
        title: "Success",
        message: "Your profile has been updated successfully!",
        color: "green",
        icon: <CheckCircle2 />,
      });
    } catch (err) {
      console.error("Update failed:", err);
      showNotification({
        title: "Update Failed",
        message: err.message || "Unable to save your changes.",
        color: "red",
        icon: <AlertCircle />,
      });
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center w-screen h-screen text-gray-500">
        Loading user data...
      </div>
    );
  }

  if (error) {
    return (
      <div className="flex flex-col items-center justify-center w-screen h-screen text-center">
        <p className="mb-4 font-semibold text-red-600">
          ⚠️ {error}
        </p>
        <button
          onClick={fetchUser}
          className="px-6 py-2 font-medium text-white bg-orange-500 rounded-md hover:bg-orange-600"
        >
          Retry
        </button>
      </div>
    );
  }

  return (
    <div className="flex flex-row w-screen h-screen bg-gray-50">
      {/* Sidebar */}
      <aside className="flex flex-col items-center justify-between w-20 h-full py-6 bg-white border-r">
        <img
          src={hocLogo}
          alt="Click N' Chick"
          className="object-contain w-auto cursor-pointer h-14"
          onClick={() => navigate("/home")}
        />
        <div className="flex flex-col gap-6 mt-6">
          <button className="p-3 text-orange-600 bg-orange-200 rounded-full">
            <User size={22} />
          </button>
        </div>
        <div className="flex flex-col gap-6 mt-6">
          <button
            className="p-3 mt-auto text-yellow-500 bg-yellow-100 rounded-full"
            onClick={logOut}
          >
            <LogOut size={22} />
          </button>
        </div>
      </aside>

      {/* Main Content */}
      <main className="flex flex-col items-start w-screen px-10 py-6 justify-self-start">
        <h1 className="mb-8 text-4xl font-medium tracking-wide text-orange-500 uppercase hoc_font">
          Settings
        </h1>

        <div className="bg-white shadow-sm rounded-2xl p-6 w-[150vh]">
          <div className="flex items-center justify-between mb-6">
            <h2 className="text-xl font-semibold">Account Information</h2>
            {!isEditing && (
              <button
                className="text-sm font-medium text-orange-500 hover:underline"
                onClick={() => setIsEditing(true)}
              >
                Edit
              </button>
            )}
          </div>

          {/* Form */}
          <div className="grid grid-cols-2 gap-6 mb-6">
            <div>
              <label className="block mb-1 text-xs text-gray-500">Username</label>
              <input
                type="text"
                name="name"
                value={formData.name}
                onChange={handleChange}
                className="w-full px-4 py-2 border rounded-full bg-gray-50 focus:ring-2 focus:ring-orange-400"
                readOnly={!isEditing}
              />
            </div>
            <div>
              <label className="block mb-1 text-xs text-gray-500">First Name</label>
              <input
                type="text"
                name="first_name"
                value={formData.first_name}
                onChange={handleChange}
                className="w-full px-4 py-2 border rounded-full bg-gray-50 focus:ring-2 focus:ring-orange-400"
                readOnly={!isEditing}
              />
            </div>
            <div>
              <label className="block mb-1 text-xs text-gray-500">Last Name</label>
              <input
                type="text"
                name="last_name"
                value={formData.last_name}
                onChange={handleChange}
                className="w-full px-4 py-2 border rounded-full bg-gray-50 focus:ring-2 focus:ring-orange-400"
                readOnly={!isEditing}
              />
            </div>
            <div>
              <label className="block mb-1 text-xs text-gray-500">Phone Number</label>
              <input
                type="text"
                name="phone_number"
                value={formData.phone_number}
                onChange={handleChange}
                className="w-full px-4 py-2 border rounded-full bg-gray-50 focus:ring-2 focus:ring-orange-400"
                readOnly={!isEditing}
              />
            </div>
            <div>
              <label className="block mb-1 text-xs text-gray-500">Note</label>
              <input
                type="text"
                name="note"
                value={formData.note}
                onChange={handleChange}
                className="w-full px-4 py-2 border rounded-full bg-gray-50 focus:ring-2 focus:ring-orange-400"
                readOnly={!isEditing}
              />
            </div>
          </div>

          {/* Location */}
          <div className="mb-6 w-full h-[30vh]">
            <label className="block mb-2 text-xs text-gray-500">
              Your Location
            </label>
            <div className="flex flex-row items-center justify-center h-full gap-4">
              <div className="relative flex-1 h-full p-4 bg-gray-50 rounded-xl">
                <h3 className="font-semibold">📍 {formData.location}</h3>
                <p className="mt-1 text-sm text-gray-500">
                  {location.full || "Location not set"}
                </p>
              </div>
              <div className="w-[40vh] h-full bg-gray-200 rounded-xl flex items-center justify-center">
                <UserLocationMap
                  editMode={isEditing}
                  setLocation={setLocation}
                  location={location}
                  user={user}
                />
              </div>
            </div>
          </div>

          {/* Save button */}
          {isEditing && (
            <button
              onClick={handleSave}
              disabled={saving}
              className={`px-6 py-2 font-semibold text-white rounded-md ${
                saving
                  ? "bg-orange-300 cursor-not-allowed"
                  : "bg-orange-500 hover:bg-orange-600"
              }`}
            >
              {saving ? "Saving..." : "Save"}
            </button>
          )}
        </div>
      </main>
    </div>
  );
}
