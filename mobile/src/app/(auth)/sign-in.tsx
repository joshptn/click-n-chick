import { StyleSheet, View } from "react-native";
import { router } from "expo-router";

import { AppText } from "../../components/AppText";
import { Button } from "../../components/Button";
import { Screen } from "../../components/Screen";
import { colors } from "../../theme/colors";

/** Signed-out only. The form arrives in phase 5. */
export default function SignIn() {
  return (
    <Screen>
      <View style={styles.block}>
        <AppText family="display" weight="bold" size={24}>
          Sign in
        </AppText>
        <AppText color={colors.ink500}>Signing in to the app is coming in the next update.</AppText>
      </View>
      <Button label="Back" variant="outline" onPress={() => (router.canGoBack() ? router.back() : router.replace("/"))} />
    </Screen>
  );
}

const styles = StyleSheet.create({
  block: { gap: 6, paddingTop: 12 },
});
