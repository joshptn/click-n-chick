import { useNetInfo } from "@react-native-community/netinfo";

/**
 * False only when NetInfo is sure there is no connection: no network at all
 * (airplane mode), or a network with no internet behind it. Unknown counts as
 * online, so the notice never flashes at launch.
 */
export function useOnline(): boolean {
  const { isConnected, isInternetReachable } = useNetInfo();

  return isConnected !== false && isInternetReachable !== false;
}

export default useOnline;
